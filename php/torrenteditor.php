<?php
/*
TorrentEditor.com API - Simple API to modify torrents
Copyright (C) 2009  Tyler Alvord

Web: http://torrenteditor.com
IRC: #torrenteditor.com on efnet.org  

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

include_once('bencode.php');

class Torrent
{
    // Private class members
    private $torrent;
    private $info;

    // Public error message, $error is set if load() returns false
    public $error;

    // Load torrent file data
    // $data - raw torrent file contents
    public function load(&$data)
    {
        $this->torrent = BEncode::decode($data);

        if ($this->torrent->get_type() == 'error') {
            $this->error = $this->torrent->get_plain();
            return false;
        } else if ($this->torrent->get_type() != 'dictionary') {
            $this->error = 'The file was not a valid torrent file.';
            return false;
        }

        $this->info = $this->torrent->get_value('info');
        if (!$this->info) {
            $this->error = 'Could not find info dictionary.';
            return false;
        }

        return true;
    }

    // Get comment
    // return - string
    public function getComment()
    {
        return $this->torrent->get_value('comment') ? $this->torrent->get_value('comment')->get_plain() : null;
    }

    // Get creatuion date
    // return - php date
    public function getCreationDate()
    {
        return  $this->torrent->get_value('creation date') ? $this->torrent->get_value('creation date')->get_plain() : null;
    }

    // Get created by
    // return - string
    public function getCreatedBy()
    {
        return $this->torrent->get_value('created by') ? $this->torrent->get_value('created by')->get_plain() : null;
    }

    // Get name
    // return - filename (single file torrent)
    //          directory (multi-file torrent)
    // see also - getFiles()
    public function getName()
    {
        return $this->info->get_value('name')->get_plain();
    }

    // Get piece length
    // return - int
    public function getPieceLength()
    {
        return $this->info->get_value('piece length')->get_plain();
    }

    // Get pieces
    // return - raw binary of peice hashes
    public function getPieces()
    {
        return $this->info->get_value('pieces')->get_plain();
    }

    // Get private flag
    // return - -1 public, implicit
    //           0 public, explicit
    //           1 private
    public function getPrivate()
    {
        if ($this->info->get_value('private')) {
            return $this->info->get_value('private')->get_plain();
        }
        return -1;
    }

    // Get a list of files
    // return - array of Torrent_File
    public function getFiles()
    {
        // Load files
        $filelist = array();
        $length = $this->info->get_value('length');

        if ($length) {
            $file = new Torrent_File();
            $file->name = $this->info->get_value('name')->get_plain();
            $file->length =  $this->info->get_value('length')->get_plain();
            array_push($filelist, $file);
        } else if ($this->info->get_value('files')) {
            $files = $this->info->get_value('files')->get_plain();
            foreach ($files as $key => $value) {
                $file = new Torrent_File();

                $path = $value->get_value('path')->get_plain();
                foreach ($path as $key => $value2) {
                    $file->name .= "/" . $value2->get_plain();
                }
                $file->name = ltrim($file->name, '/');
                $file->length =  $value->get_value('length')->get_plain();

                array_push($filelist, $file);
            }
        }

        return $filelist;
    }

    // Get a list of trackers
    // return - array of strings
    public function getTrackers()
    {
        // Load tracker list
        $trackerlist = array();

        if ($this->torrent->get_value('announce-list')) {
            $trackers = $this->torrent->get_value('announce-list')->get_plain();
            foreach ($trackers as $key => $value) {
                if (is_array($value->get_plain())) {
                    foreach ($value as $key => $value2) {
                        foreach ($value2 as $key => $value3) {
                            array_push($trackerlist, $value3->get_plain());
                        }
                    }
                } else {
                    array_push($trackerlist, $value->get_plain());
                }
            }
        } else if ($this->torrent->get_value('announce')) {
            array_push($trackerlist, $this->torrent->get_value('announce')->get_plain());
        }

        return $trackerlist;
    }

    // Helper function to make adding a tracker easier
    // $tracker_url - string
    public function addTracker($tracker_url)
    {
        $trackers = $this->getTrackers();
        $trackers[] = $tracker_url;
        $this->setTrackers($trackers);
    }

    // Replace the current trackers with the supplied list
    // $trackerlist - array of strings
    public function setTrackers($trackerlist)
    {
        if (count($trackerlist) >= 1) {
            $this->torrent->remove('announce-list');
            $string = new BEncode_String($trackerlist[0]);
            $this->torrent->set('announce', $string);
        }

        if (count($trackerlist) > 1) {
            $list = new BEncode_List();

            foreach ($trackerlist as $key => $value) {
                $list2 = new BEncode_List();
                $string = new BEncode_String($value);
                $list2->add($string);
                $list->add($list2);
            }

            $this->torrent->set('announce-list', $list);
        }
    }

    // Update the list of files
    // $filelist - array of Torrent_File
    public function setFiles($filelist)
    {
        // Load files
        $length = $this->info->get_value('length');

        if ($length) {
            $filelist[0] = str_replace('\\', '/', $filelist[0]);
            $string = new BEncode_String($filelist[0]);
            $this->info->set('name', $string);
        } else if ($this->info->get_value('files')) {
            $files = $this->info->get_value('files')->get_plain();
            for ($i = 0; $i < count($files); ++$i) {
                $file_parts = explode('/', $filelist[$i]);
                $path = new BEncode_List();
                foreach ($file_parts as $part) {
                    $string = new BEncode_String($part);
                    $path->add($string);
                }
                $files[$i]->set('path', $path);
            }
        }
    }

    // Set the comment field
    // $value - string
    public function setComment($value)
    {
        $type = 'comment';
        $key = $this->torrent->get_value($type);
        if ($value == '') {
            $this->torrent->remove($type);
        } elseif ($key) {
            $key->set($value);
        } else {
            $string = new BEncode_String($value);
            $this->torrent->set($type, $string);
        }
    }

    // Set the created by field
    // $value - string
    public function setCreatedBy($value)
    {
        $type = 'created by';
        $key = $this->torrent->get_value($type);
        if ($value == '') {
            $this->torrent->remove($type);
        } elseif ($key) {
            $key->set($value);
        } else {
            $string = new BEncode_String($value);
            $this->torrent->set($type, $string);
        }
    }

    // Set the creation date
    // $value - php date
    public function setCreationDate($value)
    {
        $type = 'creation date';
        $key = $this->torrent->get_value($type);
        if ($value == '') {
            $this->torrent->remove($type);
        } elseif ($key) {
            $key->set($value);
        } else {
            $int = new BEncode_Int($value);
            $this->torrent->set($type, $int);
        }
    }

    // Change the private flag
    // $value - -1 public, implicit
    //           0 public, explicit
    //           1 private
    public function setPrivate($value)
    {
        if ($value == -1) {
            $this->info->remove('private');
        } else {
            $int = new BEncode_Int($value);
            $this->info->set('private', $int);
        }
    }

    // Bencode the torrent
    public function bencode()
    {
        return $this->torrent->encode();
    }

    // Return the torrent's hash
    public function getHash()
    {
        return strtoupper(sha1($this->info->encode()));
    }
}

// Simple class to encapsulate filename and length
class Torrent_File
{
    public $name;
    public $length;
}
