<?php
    require_once '/data/webroot/config/config.php';

    /*
                                                                                                 /~\
                                              "These are not the droids you're looking for."    |oo )
                                                                                                _\~/_
                                                                                  ___          /  _  \
                                                                                 / ()\        //|/_\|\\
                                                                               _|_____|_     ||  \_/  ||
                                                                              | | --- | |    || |\ /| ||
                                                                              |_|  O  |_|     # \_ _/  #
                                                                               ||  O  ||        | | |
        APACHE REWRITE RULES                                                   ||__X__||        | | |
                                                                              |~ \___/ ~|       []|[]
        RewriteEngine on                                                      /-\ /-\ /-\       | | |
        RewriteRule ^/sa/.+ /vendor/subjectanalysis/saproxy.php [L]           [ ] [ ] [ ]      / ] [ \
    */

    /*
     *  Command Interface
     */

    interface Command {
        public function execute();
    }

    /*
     *  HttpCommand: A simple base class
     */

    abstract class HttpCommand implements Command {
        protected $url;

        public function __construct($url) {
            $this->url = $url;
        }

        abstract protected function setCommandOptions($ch);

        private function onHeaderCallback($curl, $header) {
            if (!preg_match('/^Transfer-Encoding|X-Powered-By/i', $header))
                header($header);

            return strlen($header);
        }

        public function execute() {
            header('X-SA-Proxy: '.$this);

            $ch = curl_init($this->url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_FAILONERROR, TRUE);
            curl_setopt($ch, CURLOPT_VERBOSE, TRUE);
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, array($this, 'onHeaderCallback'));

            $this->setCommandOptions($ch);
            $response = curl_exec($ch);

            if (curl_errno($ch))
                http_response_code(curl_getinfo($ch, CURLINFO_HTTP_CODE));

            curl_close($ch);
            return $response;
        }

        public function __toString() {
            return $this->url;
        }
    }

    /*
     *  Post Command
     */

    class PostCommand extends HttpCommand {
        private function isMultiPart() {
            return preg_match('#^multipart/form-data#i', $_SERVER['CONTENT_TYPE']);
        }

        private function buildMultiPart($boundary) {
            $retval = '';

            foreach ($_FILES as $key => $value) {
                $name = $value['name'];
                $filename = $value['tmp_name'];
                $type = $value['type'];

                $retval .= implode("\r\n", array(
                    "--$boundary",
                    "Content-Disposition: form-data; filename=\"$name\"",
                    "Content-Type: $type",
                    "",
                    file_get_contents($filename), 
                    ""
                ));
            }

            $retval .= "--$boundary--";
            return $retval;
        }

        private function getMultiPart() {
            $boundary = '';
            if (preg_match('/boundary=(.+)/i', $_SERVER['CONTENT_TYPE'], $match))
                $boundary = $match[1];

            return $this->buildMultiPart($boundary);
        }

        protected function setCommandOptions($ch) {
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->isMultiPart() ? $this->getMultiPart() : file_get_contents('php://input'));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: '.$_SERVER['CONTENT_TYPE']));
        }

        public function __toString() {
            return 'POST '.parent::__toString();
        }    
    }

    /*
     *  Get Command
     */

    class GetCommand extends HttpCommand {
        protected function setCommandOptions($ch) {
            curl_setopt($ch, CURLOPT_HTTPGET, TRUE);
        }

        public function __toString() {
            return 'GET '.parent::__toString();
        }
    }

    /*
     *  Put Command
     */

    class PutCommand extends HttpCommand {
        protected function setCommandOptions($ch) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        }

        public function __toString() {
            return 'PUT '.parent::__toString();
        }
    }

    /*
     *  Delete Command
     */

    class DeleteCommand extends HttpCommand {
        protected function setCommandOptions($ch) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        public function __toString() {
            return 'DELETE '.parent::__toString();
        }
    }

    /*
     *  Command Factory: Where all the magic happens.
     */

    class Factory {
        private static $commands = array('POST' => 'PostCommand', 'GET' => 'GetCommand', 'PUT' => 'PutCommand', 'DELETE' => 'DeleteCommand');

        private static function buildUrl() {
            return SA_ENDPOINT.preg_replace('#^/[^/]+/#', '', $_SERVER['REQUEST_URI']);
        }

        public static function createCommand() {
            return new Factory::$commands[$_SERVER['REQUEST_METHOD']](Factory::buildUrl());
        }
    }

    /*
     *  Execute the appropriate command.
     */

    $command = Factory::createCommand();
    print $command->execute();
?>
