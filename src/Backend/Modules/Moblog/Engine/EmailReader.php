<?php

namespace Backend\Modules\Moblog\Engine;

use Backend\Core\Engine\Exception;

/**
 * Class EmailReader
 *
 * Helper class to read emails and extract attachments.
 *
 * Based on blog posts from Garrett St. John
 *
 * @link http://garrettstjohn.com/entry/reading-emails-with-php/
 */
class EmailReader
{

    /** @var $conn */
    public $conn;
    /** @var array $inbox */
    private $inbox = array();
    /** @var int $msg_cnt */
    private $msg_cnt = 0;
    /** @var string $server */
    private $server;
    /** @var string $user */
    private $user;
    /** @var string $pass */
    private $pass;
    /** @var int $port */
    private $port;
    /** @var string $connectString */
    private $connectString = 'imap/notls';
    /** @var string $processedFolder */
    private $processedFolder;

    /**
     * Connect to the server and get the inbox emails
     *
     * @param        $server
     * @param        $user
     * @param        $pass
     * @param int $port
     * @param bool $useSSL
     * @param string $processedFolder
     *
     * @throws Exception
     */
    public function __construct(
        $server,
        $user,
        $pass,
        $port = 143,
        $useSSL = false,
        $processedFolder = 'INBOX.Processed'
    ) {
        $this->server = $server;
        $this->user = $user;
        $this->pass = $pass;
        $this->port = $port;
        $this->useSSL($useSSL);
        $this->processedFolder = $processedFolder;
        $this->connect();
        $this->inbox();
    }

    /**
     * set the tls connection string
     */
    private function useSSL($useSSL)
    {
        if ($useSSL) {
            $this->connectString = 'imap/ssl';
        }
    }

    /**
     * @return int
     */
    public function getMsgCount()
    {
        return $this->msg_cnt;
    }

    /**
     * close the server connection
     */
    public function close()
    {
        $this->inbox = array();
        $this->msg_cnt = 0;
        imap_close($this->conn);
    }

    /**
     * Open the server connection
     */
    public function connect()
    {
        $server = '{' . $this->server . ':' . $this->port . '/' . $this->connectString . '}INBOX';
        $status = $this->conn = imap_open($server, $this->user, $this->pass);
        if (!$status) {
            throw new Exception("Failed to connect to mail server: " . $server . "!");
        }
    }

    /**
     * Move the message to a new folder
     */
    public function move($msg_index, $folder = '')
    {
        // move on server
        $status = \imap_mail_move($this->conn, $msg_index, $this->processedFolder);
        if (!$status) {
            throw new Exception("Failed to move mail with id: " . $msg_index . "!");
        }
        $status = imap_expunge($this->conn);
        if (!$status) {
            throw new Exception("Failed to expunge Inbox!");
        }
        // re-read the inbox
        $this->inbox();
    }

    /**
     * Get a specific message (1 = first email, 2 = second email, etc.)
     *
     * @param null $msg_index
     *
     * @return array
     */
    public function get($msg_index = null)
    {
        if (count($this->inbox) <= 0) {
            return array();
        } elseif (!is_null($msg_index) && isset($this->inbox[$msg_index])) {
            return $this->inbox[$msg_index];
        }

        return $this->inbox[0];
    }

    /**
     * read the inbox
     */
    public function inbox()
    {
        $this->msg_cnt = \imap_num_msg($this->conn);
        $in = array();
        for ($i = 1; $i <= $this->msg_cnt; $i++) {
            $in[] = array(
                'index' => $i,
                'header' => imap_headerinfo($this->conn, $i),
                'body' => imap_body($this->conn, $i),
                'structure' => imap_fetchstructure($this->conn, $i)
            );
        }
        $this->inbox = $in;
    }
}
