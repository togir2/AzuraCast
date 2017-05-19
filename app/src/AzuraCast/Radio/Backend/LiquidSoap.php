<?php
namespace AzuraCast\Radio\Backend;

class LiquidSoap extends BackendAbstract
{
    public function read()
    {
    }

    /**
     * Write configuration from Station object to the external service.
     * @return bool
     */
    public function write()
    {
        $settings = (array)$this->station->backend_config;

        $playlist_path = $this->station->getRadioPlaylistsDir();
        $config_path = $this->station->getRadioConfigDir();

        $ls_config = [
            '# WARNING! This file is automatically generated by AzuraCast.',
            '# Do not update it directly!',
            '',
            'set("init.daemon", false)',
            'set("init.daemon.pidfile.path","' . $config_path . '/liquidsoap.pid")',
            'set("log.file.path","' . $config_path . '/liquidsoap.log")',
            'set("server.telnet",true)',
            'set("server.telnet.bind_addr","127.0.0.1")',
            'set("server.telnet.port", ' . $this->_getTelnetPort() . ')',
            'set("server.telnet.reverse_dns",false)',
            'set("harbor.bind_addr","0.0.0.0")',
            'set("harbor.reverse_dns",false)',
            '',
            '# AutoDJ Next Song Script',
            'def azuracast_next_song() =',
            '  uri = get_process_lines("/usr/bin/php '.APP_INCLUDE_ROOT.'/util/cli.php azuracast:internal:next-song '.$this->station->id.'")',
            '  uri = list.hd(uri)',
            '  log("AzuraCast Raw Response: #{uri}")',
            '  request.create(uri)',
            'end',
            '',
            '# DJ Authentication',
            'def dj_auth(user,password) =',
            '  log("Authenticating DJ: #{user}")',
            '  ret = get_process_lines("/usr/bin/php '.APP_INCLUDE_ROOT.'/util/cli.php azuracast:internal:streamer-auth '.$this->station->id.' #{user} #{password}")',
            '  ret = list.hd(ret)',
            '  bool_of_string(ret)',
            'end',
            '',
            'live_enabled = ref false',
            '',
            'def live_connected(header) =',
            '    log("DJ Source connected!")',
            '    live_enabled := true',
            'end',
            '',
            'def live_disconnected() =',
            '   log("DJ Source disconnected!")',
            '   live_enabled := false',
            'end',
            '',
        ];

        // Clear out existing playlists directory.
        $current_playlists = array_diff(scandir($playlist_path), ['..', '.']);
        foreach ($current_playlists as $list) {
            @unlink($playlist_path . '/' . $list);
        }

        // Set up playlists using older format as a fallback.
        $ls_config[] = '# Fallback Playlists';

        $playlists_by_type = [];
        $playlists = [];

        foreach ($this->station->playlists as $playlist_raw) {
            /** @var \Entity\StationPlaylist $playlist_raw */
            if (!$playlist_raw->is_enabled) {
                continue;
            }

            $playlist_file_contents = $playlist_raw->export('m3u', true);

            $playlist = $playlist_raw->toArray($this->di['em']);
            $playlist['var_name'] = 'playlist_' . $playlist_raw->getShortName();
            $playlist['file_path'] = $playlist_path . '/' . $playlist['var_name'] . '.m3u';

            file_put_contents($playlist['file_path'], $playlist_file_contents);

            $ls_config[] = $playlist['var_name'] . ' = playlist(reload_mode="watch","' . $playlist['file_path'] . '")';

            $playlist_type = $playlist['type'] ?: 'default';
            $playlists_by_type[$playlist_type][] = $playlist;
            $playlists[] = $playlist;
        }

        if (empty($playlists_by_type['default'])) {
            if (count($playlists) > 0) {
                $this->log('LiquidSoap will not start until at least one playlist is set as the "Default" type.',
                    'error');
            }

            return false;
        }

        $ls_config[] = '';

        // Create fallback playlist based on all default playlists.
        $playlist_weights = [];
        $playlist_vars = [];
        foreach ($playlists_by_type['default'] as $playlist) {
            $playlist_weights[] = $playlist['weight'];
            $playlist_vars[] = $playlist['var_name'];
        }

        $ls_config[] = 'playlists = random(weights=[' . implode(', ', $playlist_weights) . '], [' . implode(', ',
                $playlist_vars) . ']);';

        $ls_config[] = 'dynamic = request.dynamic(id="azuracast_next_song", azuracast_next_song)';
        $ls_config[] = 'dynamic = cue_cut(id="azuracast_next_song_cued", dynamic)';
        $ls_config[] = 'radio = fallback(track_sensitive = false, [dynamic, playlists, blank(duration=2.)])';
        $ls_config[] = '';

        // Add harbor live.
        $harbor_params = [
            '"/"',
            'port='.$this->getStreamPort(),
            'user="shoutcast"',
            'auth=dj_auth',
            'icy=true',
            'max=30.',
            'buffer=5.',
            'on_connect=live_connected',
            'on_disconnect=live_disconnected',
        ];

        $ls_config[] = 'live = audio_to_stereo(input.harbor('.implode(', ', $harbor_params).'))';
        $ls_config[] = 'ignore(output.dummy(live, fallible=true))';
        $ls_config[] = 'live = fallback(track_sensitive=false, [live, blank(duration=2.)])';

        $ls_config[] = '';
        $ls_config[] = 'radio = switch(id="live_switch", track_sensitive=false, [({!live_enabled}, live), ({true}, radio)])';
        $ls_config[] = '';

        // Crossfading
        $crossfade = (int)($settings['crossfade'] ?? 2);
        if ($crossfade > 0) {
            $start_next = round($crossfade * 1.5);
            $ls_config[] = '# Crossfading';
            $ls_config[] = 'radio = crossfade(start_next=' . $start_next . '.,fade_out=' . $crossfade . '.,fade_in=' . $crossfade . '.,radio)';
            $ls_config[] = '';
        }

        if (!empty($settings['custom_config'])) {
            $ls_config[] = '# Custom Configuration (Specified in Station Profile)';
            $ls_config[] = $settings['custom_config'];
            $ls_config[] = '';
        }

        $ls_config[] = '# Outbound Broadcast';

        // Configure the outbound broadcast.
        $fe_settings = (array)$this->station->frontend_config;

        $broadcast_port = $fe_settings['port'];
        $broadcast_source_pw = $fe_settings['source_pw'];

        $settings_repo = $this->di['em']->getRepository('Entity\Settings');
        $base_url = $settings_repo->getSetting('base_url', 'localhost');

        switch ($this->station->frontend_type) {
            case 'remote':
                $this->log(_('You cannot use an AutoDJ with a remote frontend. Please change the frontend type or update the backend to be "Disabled".'),
                    'error');

                return false;
                break;

            case 'shoutcast2':
                $i = 0;
                foreach ($this->station->mounts as $mount_row) {
                    $i++;

                    if (!$mount_row->enable_autodj) {
                        continue;
                    }

                    $format = strtolower($mount_row->autodj_format ?: 'mp3');
                    $bitrate = $mount_row->autodj_bitrate ?: 128;

                    if ($format == 'aac') {
                        $output_format = '%fdkaac(channels=2, samplerate=44100, bitrate='.(int)$bitrate.', afterburner=true, aot="mpeg4_he_aac_v2", transmux="adts", sbr_mode=true)';
                    } else {
                        $output_format = '%mp3.cbr(samplerate=44100,stereo=true,bitrate=' . (int)$bitrate . ')';
                    }

                    $output_params = [
                        $output_format, // Required output format (%mp3 etc)
                        'id="radio_out_' . $i . '"',
                        'host = "localhost"',
                        'port = ' . ($broadcast_port),
                        'password = "' . $broadcast_source_pw . ':#'.$i.'"',
                        'name = "' . $this->_cleanUpString($this->station->name) . '"',
                        'url = "' . $this->_cleanUpString($this->station->url ?: $base_url) . '"',
                        'public = false',
                        'radio', // Required
                    ];
                    $ls_config[] = 'output.shoutcast(' . implode(', ', $output_params) . ')';
                }
                break;

            case 'icecast':
            default:
                $i = 0;
                foreach ($this->station->mounts as $mount_row) {
                    $i++;

                    if (!$mount_row->enable_autodj) {
                        continue;
                    }

                    $format = strtolower($mount_row->autodj_format ?: 'mp3');
                    $bitrate = $mount_row->autodj_bitrate ?: 128;

                    if ($format == 'ogg') {
                        $output_format = '%vorbis.cbr(samplerate=44100, channels=2, bitrate=' . (int)$bitrate . ')';
                    } else {
                        $output_format = '%mp3.cbr(samplerate=44100,stereo=true,bitrate=' . (int)$bitrate . ')';
                    }

                    if (!empty($output_format)) {
                        $output_params = [
                            $output_format, // Required output format (%mp3 or %ogg)
                            'id="radio_out_' . $i . '"',
                            'host = "localhost"',
                            'port = ' . $broadcast_port,
                            'password = "' . $broadcast_source_pw . '"',
                            'name = "' . $this->_cleanUpString($this->station->name) . '"',
                            'description = "' . $this->_cleanUpString($this->station->description) . '"',
                            'url = "' . $this->_cleanUpString($this->station->url ?: $base_url) . '"',
                            'mount = "' . $mount_row->name . '"',
                            'radio', // Required
                        ];
                        $ls_config[] = 'output.icecast(' . implode(', ', $output_params) . ')';
                    }
                }
                break;
        }

        $ls_config_contents = implode("\n", $ls_config);

        $ls_config_path = $config_path . '/liquidsoap.liq';
        file_put_contents($ls_config_path, $ls_config_contents);

        return true;
    }

    protected function _cleanUpString($string)
    {
        return str_replace(['"', "\n", "\r"], ['\'', '', ''], $string);
    }

    protected function _getTime($time_code)
    {
        $hours = floor($time_code / 100);
        $mins = $time_code % 100;

        $system_time_zone = \App\Utilities::get_system_time_zone();
        $system_tz = new \DateTimeZone($system_time_zone);
        $system_dt = new \DateTime('now', $system_tz);
        $system_offset = $system_tz->getOffset($system_dt);

        $app_tz = new \DateTimeZone(date_default_timezone_get());
        $app_dt = new \DateTime('now', $app_tz);
        $app_offset = $app_tz->getOffset($app_dt);

        $offset = $system_offset - $app_offset;
        $offset_hours = floor($offset / 3600);

        $hours += $offset_hours;

        $hours = $hours % 24;
        if ($hours < 0) {
            $hours += 24;
        }

        return $hours . 'h' . $mins . 'm';
    }

    public function getCommand()
    {
        $user_base = realpath(APP_INCLUDE_ROOT.'/..');
        $config_path = $this->station->getRadioConfigDir() . '/liquidsoap.liq';

        return $user_base.'/.opam/system/bin/liquidsoap ' . $config_path;
    }

    public function skip()
    {
        return $this->command('radio_out_1.skip');
    }

    public function command($command_str)
    {
        $fp = stream_socket_client('tcp://localhost:' . $this->_getTelnetPort(), $errno, $errstr, 20);

        if (!$fp) {
            throw new \App\Exception('Telnet failure: ' . $errstr . ' (' . $errno . ')');
        }

        fwrite($fp, str_replace(["\\'", '&amp;'], ["'", '&'], urldecode($command_str)) . "\nquit\n");

        $response = [];
        while (!feof($fp)) {
            $response[] = trim(fgets($fp, 1024));
        }

        fclose($fp);

        return $response;
    }

    public function getStreamPort()
    {
        return (8000 + (($this->station->id - 1) * 10) + 5);
    }

    protected function _getTelnetPort()
    {
        return (8500 + (($this->station->id - 1) * 10));
    }

    /*
     * Static Functions
     */

    public static function isInstalled()
    {
        $user_base = realpath(APP_INCLUDE_ROOT.'/..');
        return file_exists($user_base.'/.opam/system/bin/liquidsoap');
    }
}