<?php

namespace Backend\Modules\Moblog\Cronjobs;

use Backend\Core\Engine\Base\Cronjob as BackendBaseCronjob;
use Backend\Modules\Blog\Engine\Model;
use Backend\Modules\Moblog\Engine\EmailReader;

/**
 * Class FetchAndProcessEmails
 *
 * This cronjob will fetch email and proccess them in moblogs.
 *
 * @author Patrick Jezek <patrick@jezek.ch>
 */
class FetchAndProcessEmails extends BackendBaseCronjob
{
    /** @var EmailReader $emailReader */
    protected $emailReader;
    /** @var string $pictureFolder */
    protected $pictureFolder;
    /** @var string $pictureUrl */
    protected $pictureUrl;
    /** @var array $pictureFolder */
    protected $tags;
    /** @var int $pictureFolder */
    protected $userId;
    /** @var int $categoryId */
    protected $categoryId;
    /** @var int $size */
    protected $size = 320;

    /**
     * Helper to convert exif coordinates strings.
     *
     * Based on Answers from stackoverflow
     * @link http://stackoverflow.com/questions/2526304/php-extract-gps-exif-data
     *
     * @param $coordPart
     * @return float|int
     */
    function gps2Num($coordPart)
    {
        $parts = explode('/', $coordPart);

        if (count($parts) <= 0) {
            return 0;
        }

        if (count($parts) == 1) {
            return $parts[0];
        }

        return floatval($parts[0]) / floatval($parts[1]);
    }

    /**
     * Helper to convert exif coordinates to lat long.
     *
     * Based on Answers from stackoverflow
     * @link http://stackoverflow.com/questions/2526304/php-extract-gps-exif-data
     *
     * @param $exifCoord
     * @param $hemi
     * @return int
     */
    function getGps($exifCoord, $hemi)
    {
        $degrees = count($exifCoord) > 0 ? $this->gps2Num($exifCoord[0]) : 0;
        $minutes = count($exifCoord) > 1 ? $this->gps2Num($exifCoord[1]) : 0;
        $seconds = count($exifCoord) > 2 ? $this->gps2Num($exifCoord[2]) : 0;

        $flip = ($hemi == 'W' or $hemi == 'S') ? -1 : 1;

        return $flip * ($degrees + $minutes / 60 + $seconds / 3600);
    }

    /**
     * Helper to create plazes markup.
     *
     * @param $filename
     * @return string
     */
    protected function getPlazesString($filename)
    {
        $geo = '';
        if (function_exists('exif_read_data')) {
            $exif = exif_read_data($filename);
            $lon = $this->getGps($exif["GPSLongitude"], $exif['GPSLongitudeRef']);
            $lat = $this->getGps($exif["GPSLatitude"], $exif['GPSLatitudeRef']);

            $geo = '<plazes fromplazes="false"><plazelat>' . $lat . '</plazelat><plazelon>' . $lon . '</plazelon><plazename></plazename></plazes>';
        }

        return $geo;
    }

    /**
     * @param $data
     * @param $orgName
     * @param $extension
     *
     * @return array
     * @throws \SpoonThumbnailException
     */
    protected function uploadImage($data, $orgName, $extension)
    {
        $fileName = sha1($data) . "." . $extension;
        $orgFile = $this->pictureFolder . '/source/' . $fileName;
        file_put_contents($orgFile, $data);
        $thumbnail = new \SpoonThumbnail($orgFile, $this->size);
        $thumbnail->setAllowEnlargement(true);
        $thumbnail->parseToFile($this->pictureFolder . '/' . $this->size . 'x/' . $fileName);
        $geo = $this->getPlazesString($orgFile);

        return array(
            'original' => array('path' => $orgFile, 'url' => $this->pictureUrl . '/source/' . $fileName),
            'small' => array(
                'path' => $this->pictureFolder . '/' . $this->size . 'x/' . $fileName,
                'url' => $this->pictureUrl . '/' . $this->size . 'x/' . $fileName
            ),
            'geo' => $geo,
        );
    }

    /**
     * This method is run on a cronjob and should process all emails in the inbox.
     *
     * @return array
     */
    protected function pullEmails()
    {
        $model = array();

        while (1) {
            // get an email
            $email = $this->emailReader->get();

            // if there are no emails, jump out
            if (count($email) <= 0) {
                break;
            }

            $attachments = array();

            // check for attachments
            if (isset($email['structure']->parts) && count($email['structure']->parts)) {
                // loop through all attachments
                for ($i = 0; $i < count($email['structure']->parts); $i++) {
                    // set up an empty attachment
                    $attachments[$i] = array(
                        'is_attachment' => false,
                        'filename' => '',
                        'name' => '',
                        'attachment' => ''
                    );

                    // if this attachment has idfparameters, then proceed
                    if ($email['structure']->parts[$i]->ifdparameters) {
                        foreach ($email['structure']->parts[$i]->dparameters as $object) {
                            // if this attachment is a file, mark the attachment and filename
                            if (strtolower($object->attribute) == 'filename') {
                                $attachments[$i]['is_attachment'] = true;
                                $attachments[$i]['filename'] = $object->value;
                            }
                        }
                    }

                    // if this attachment has ifparameters, then proceed as above
                    if ($email['structure']->parts[$i]->ifparameters) {
                        foreach ($email['structure']->parts[$i]->parameters as $object) {
                            if (strtolower($object->attribute) == 'name') {
                                $attachments[$i]['is_attachment'] = true;
                                $attachments[$i]['name'] = $object->value;
                            }
                        }
                    }

                    // if we found a valid attachment for this 'part' of the email, process the attachment
                    if ($attachments[$i]['is_attachment']) {
                        // get the content of the attachment
                        $attachments[$i]['attachment'] = imap_fetchbody(
                            $this->emailReader->conn,
                            $email['index'],
                            $i + 1
                        );
                        // check if this is base64 encoding
                        if ($email['structure']->parts[$i]->encoding == 3) { // 3 = BASE64
                            $attachments[$i]['is_plain'] = false;
                            $attachments[$i]['attachment'] = base64_decode($attachments[$i]['attachment']);
                        } // otherwise, check if this is "quoted-printable" format
                        elseif ($email['structure']->parts[$i]->encoding == 4) { // 4 = QUOTED-PRINTABLE
                            $attachments[$i]['is_plain'] = false;
                            $attachments[$i]['attachment'] = quoted_printable_decode($attachments[$i]['attachment']);
                        }
                    } else {
                        // check if this is PLAIN encoding
                        if ($email['structure']->parts[$i]->encoding == 1 || $email['structure']->parts[$i]->subtype == "PLAIN") { // 3 = PLAIN
                            $attachments[$i]['is_plain'] = true;
                            $attachments[$i]['attachment'] = imap_fetchbody(
                                $this->emailReader->conn,
                                $email['index'],
                                $i + 1
                            );
                        }
                    }
                }
            }

            // for My Slow Low, check if I found an image attachment
            $found_img = false;
            $body = "";
            foreach ($attachments as $a) {
                if ($a['is_attachment'] == true) {
                    // get information on the file
                    $finfo = pathinfo($a['filename']);

                    // check if the file is a jpg, png, or gif
                    if (preg_match('/(jpeg|jpg|gif|png)/i', $finfo['extension'], $n)) {
                        $found_img = true;
                        // process the image (save, resize, crop, etc.)
                        $attachmentNames = $this->uploadImage($a['attachment'], $a['filename'], $n[1]);
                        break;
                    }
                } else {
                    if (isset($a['is_plain']) && $a['is_plain'] == true) {
                        // concat plain text bodies
                        $body .= $a['attachment'];
                    }
                }
            }

            // if there was no image, move the email to the Rejected folder on the server
            if (!$found_img) {
                $this->emailReader->move($email['index'], 'INBOX.Rejected');
                continue;
            }

            // get content from the email that I want to store
            $addr = $email['header']->from[0]->mailbox . "@" . $email['header']->from[0]->host;
            $sender = $email['header']->from[0]->mailbox;
            $subject = (!empty($email['header']->subject) ? $email['header']->subject : '');

            // move the email to Processed folder on the server
            $this->emailReader->move($email['index'], 'INBOX.Processed');

            $mailDate = 'now';
            if (!empty($email['header']->MailDate)) {
                $mailDate = $email['header']->MailDate;
            }
            $date = new \DateTime($mailDate);

            // add the data to the database
            $model[] = array(
                'username' => $sender,
                'email' => $addr,
                'photo' => $attachmentNames,
                'title' => $subject,
                'body' => $body,
                'date' => $date->format('Y-m-d H:m:s'),
            );

            // don't slam the server
            sleep(1);
        }

        // close the connection to the IMAP server
        $this->emailReader->close();

        return $model;
    }

    /**
     * Helper to create lightbox markup.
     *
     * @param array $files
     * @return string
     */
    protected function createLightBoxLink(array $files)
    {
        return '<a href="' . $files['original']['url'] . '" rel="lightbox"><img src="' . $files['small']['url'] . '" border="0" alt=""></a>';
    }

    /**
     * Creates a blog post from extracted mail data.
     *
     * @param array $blogs
     * @throws \Backend\Core\Engine\Exception
     */
    protected function createBlogPost(array $blogs)
    {
        foreach ($blogs as $blog) {
            $item = array();
            $item['user_id'] = $this->userId; // $blog['username'], $blog['email']
            $item['status'] = 'active';
            $item['allow_comments'] = 'N';
            $item['title'] = $blog['title'];
            $item['text'] = $blog['body'] . '<br/>' . $this->createLightBoxLink($blog['photo']) . $blog['photo']['geo'];
            $item['created_on'] = $blog['date'];
            $item['publish_on'] = $blog['date'];
            $item['edited_on'] = $blog['date'];
            $item['category_id'] = $this->categoryId;

            Model::insertCompletePost($item, array(), $this->tags, array());
        }
    }

    /**
     * Helper to create needed folders.
     */
    protected function checkFolders()
    {
        \SpoonDirectory::create(dirname($this->pictureFolder));
        \SpoonDirectory::create(dirname($this->pictureFolder . '/source'));
        \SpoonDirectory::create(dirname($this->pictureFolder . '/' . $this->size . 'x/'));
    }

    /**
     * Execute the action
     */
    public function execute()
    {
        parent::execute();
        $container = $this->getContainer();
        $this->emailReader = new EmailReader(
            $container->getParameter('imap.server'),
            $container->getParameter('imap.user'),
            $container->getParameter('imap.password'),
            $container->getParameter('imap.port'),
            $container->getParameter('imap.useTls'),
            $container->getParameter('imap.processedFolder')
        );
        $this->pictureFolder = FRONTEND_FILES_PATH . '/userfiles/images/' . $container->getParameter('moblog.pictureFolder');
        $this->pictureUrl = FRONTEND_FILES_URL . '/userfiles/images/' . $container->getParameter('moblog.pictureFolder');
        $this->tags = $container->getParameter('moblog.tags');
        $this->userId = $container->getParameter('moblog.userId');
        $this->categoryId = $container->getParameter('moblog.categoryId');
        $this->checkFolders();
        $this->createBlogPost($this->pullEmails());
    }
}
