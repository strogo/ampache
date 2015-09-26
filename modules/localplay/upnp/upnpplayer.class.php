<?php 
/**
 *
 * LICENSE: GNU General Public License, version 2 (GPLv2)
 * Copyright 2001 - 2015 Ampache.org
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License v2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 */

/**
 * UPnPPlayer Class
 *
 * This player controls an instance of UPnP player
 *
 */
class UPnPPlayer
{
    /* @var UPnPPlaylist $object */
    private $_playlist = null;

    /* @var UPnPDevice $object */
    private $_device;

    private $_description_url = null;

    // 0 - stopped, 1 - playing
    private $_intState = 0;

    /**
     * Lazy initialization for UPNP device property
     * @return UPnPDevice
     */
    private function Device()
    {
        if (is_null($this->_device)) {
            $this->_device = new UPnPDevice($this->_description_url);
        }
        return $this->_device;
    }

    /**
     * Lazy initialization for UPNP playlist property
     * @return UPnPPlaylist
     */
    private function Playlist()
    {
        if (is_null($this->_playlist)) {
            $this->_playlist = new UPnPPlaylist($this->_description_url);
        }
        return $this->_playlist;
    }


    /**
     * UPnPPlayer
     * This is the constructor,
     */
    public function UPnPPlayer($name = "noname", $description_url = "http://localhost")
    {
        require_once AmpConfig::get('prefix') . '/modules/localplay/upnp/upnpdevice.php';
        require_once AmpConfig::get('prefix') . '/modules/localplay/upnp/upnpplaylist.php';

        debug_event('upnpPlayer', 'constructor: ' . $name . ' | ' . $description_url, 5);
        $this->_description_url = $description_url;

        $this->ReadIndState();
    }

    /**
     * add
     * append a song to the playlist
     * $name    Name to be shown in the playlist
     * $link    URL of the song
     */
    public function PlayListAdd($name, $link)
    {
        $this->Playlist()->Add($name, $link);
        return true;
    }

    /**
     * delete_pos
     * This deletes a specific track
     */
    public function PlaylistRemove($track)
    {
        $this->Playlist()->RemoveTrack($track);
        return true;
    }

    public function PlaylistClear()
    {
        $this->Playlist()->Clear();
        return true;
    }

     /**
     * GetPlayListItems
     * This returns a delimited string of all of the filenames
     * current in your playlist, only url's at the moment
     */
    public function GetPlaylistItems()
    {
        return $this->Playlist()->AllItems();
    }

    public function GetCurrentItem()
    {
        return $this->Playlist()->CurrentItem();
    }

    public function GetState()
    {
        $response = $this->Device()->instanceOnly('GetTransportInfo');
        $responseXML = simplexml_load_string($response);
        list($state) = $responseXML->xpath('//CurrentTransportState');

        //!!debug_event('upnpPlayer', 'GetState = ' . $state, 5);

        return $state;
    }

    /**
     * next
     * go to next song
     */
    public function Next($forcePlay = true)
    {
        // get current internal play state, for case if someone has changed it
        if (! $forcePlay) {
            $this->ReadIndState();
        }
        if (($forcePlay || ($this->_intState == 1)) && ($this->Playlist()->Next())) {
            $this->Play();
            return true;
        }
        return false;
    }

    /**
     * prev
     * go to previous song
     */
    public function Prev()
    {
        if ($this->Playlist()->Prev()) {
            $this->Play();
            return true;
        }
        return false;
    }

    /**
     * skip
     * This skips to POS in the playlist
     */
    public function Skip($pos)
    {
        if ($this->Playlist()->Skip($pos)) {
            $this->Play();
            return true;
        }
        return false;
    }

    private function prepareURIRequest($song, $prefix)
    {
        if ($song == null) {
            return null;
        }

        $songUrl = $song['link'];
        $songId = preg_replace('/(.+)\/oid\/(\d+)\/(.+)/i', '${2}', $songUrl);

        $song = new song($songId);
        $song->format();
        $songItem = Upnp_Api::_itemSong($song, '');
        $domDIDL = Upnp_Api::createDIDL($songItem);
        $xmlDIDL = $domDIDL->saveXML();

        return array(
            'InstanceID' => 0,
            $prefix . 'URI' => $songUrl,
            $prefix . 'URIMetaData' => htmlentities($xmlDIDL)
        );
    }

    private function CallAsyncURL($url)
    {
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_FRESH_CONNECT, true );
        curl_setopt( $ch, CURLOPT_HEADER, false );
        curl_exec( $ch );
        curl_close( $ch );
    }

    /**
     * play
     * play the current song
     */
    public function Play()
    {
        //!!$this->Stop();

        $this->SetIntState(1);

        $currentSongArgs = $this->prepareURIRequest($this->Playlist()->CurrentItem(), "Current");
        $response = $this->Device()->sendRequestToDevice('SetAVTransportURI', $currentSongArgs, 'AVTransport');

        $args = array( 'InstanceID' => 0, 'Speed' => 1);
        $response = $this->Device()->sendRequestToDevice('Play', $args, 'AVTransport');

        //!! UPNP subscription work not for all renderers, and works strange
        //!! so now is not used
        //$sid = $this->Device()->Subscribe();
        //$_SESSION['upnp_SID'] = $sid;

        // launch special page in background for periodically check play status
        $url = AmpConfig::get('local_web_path') . "/upnp/playstatus.php";
        $this->CallAsyncURL($url);

        return true;
    }

    /**
     * Stop
     * stops the current song amazing!
     */
    public function Stop()
    {
        $this->SetIntState(0);
        $response = $this->Device()->instanceOnly('Stop');

        //!! UPNP subscription work not for all renderers, and works strange
        //!! so now is not used
        //$sid = $_SESSION['upnp_SID'];
        //$_SESSION['upnp_SID'] = "";
        //$this->Device()->UnSubscribe($sid);

        return true;
    }

    /**
     * pause
     * toggle pause mode on current song
     */
    public function Pause()
    {
        $state = $this->GetState();
        debug_event('upnpPlayer', 'Pause. prev state = ' . $state, 5);

        if ($state == 'PLAYING') {
            $response = $this->Device()->instanceOnly('Pause');
        } else {
            $args = array( 'InstanceID' => 0, 'Speed' => 1);
            $response = $this->Device()->sendRequestToDevice('Play', $args, 'AVTransport');
        }

        return true;
    }

    /**
     * Repeat
     * This toggles the repeat state
     */
    public function Repeat($value)
    {
        //!! TODO not implemented yet
        return true;
    }

    /**
     * Random
     * this toggles the random state
     */
    public function Random($value)
    {
        //!! TODO not implemented yet
        return true;
    }

    /**
     *
     *
     */
    public function FullState()
    {
        //!! TODO not implemented yet
        return "";
    }


    /**
     * VolumeUp
     * increases the volume
     */
    public function VolumeUp()
    {
        $volume = $this->GetVolume() + 2;
        return $this->SetVolume($volume);
    }

    /**
     * VolumeDown
     * decreases the volume
     */
    public function VolumeDown()
    {
        $volume = $this->GetVolume() - 2;
        return $this->SetVolume($volume);
    }

    /**
     * SetVolume
     */
    public function SetVolume($value)
    {
        $desiredVolume = Max(0, Min(100, $value));
        $instanceId = 0;
        $channel = 'Master';

        $response = $this->Device()->sendRequestToDevice( 'SetVolume', array(
            'InstanceID' => $instanceId,
            'Channel' => $channel,
            'DesiredVolume' => $desiredVolume
        ));

        return true;
    }

    /**
     * GetVolume
     */
    public function GetVolume()
    {
        $instanceId = 0;
        $channel = 'Master';

        $response = $this->Device()->sendRequestToDevice( 'GetVolume', array(
            'InstanceID' => $instanceId,
            'Channel' => $channel
        ));

        $responseXML = simplexml_load_string($response);
        list($volume) = ($responseXML->xpath('//CurrentVolume'));
        debug_event('upnpPlayer', 'GetVolume:' . $volume, 5);

        return $volume;
    }


    private function SetIntState($state)
    {
        $this->_intState = $state;

        $sid = 'upnp_ply_' . $this->_description_url;
        $data = serialize($this->_intState);
        if (! Session::exists('api', $sid)) {
            Session::create(array('type' => 'api', 'sid' => $sid, 'value' => $data ));
        } else {
            Session::write($sid, $data);
        }
        debug_event('upnpPlayer', 'SetIntState:' . $this->_intState, 5);
    }

    private function ReadIndState()
    {
        $sid = 'upnp_ply_' . $this->_description_url;
        $data = Session::read($sid);

        $this->_intState = unserialize($data);
        debug_event('upnpPlayer', 'ReadIndState:' . $this->_intState, 5);
    }
} // End UPnPPlayer Class
?>