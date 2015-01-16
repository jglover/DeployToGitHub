<?php
namespace com\jamesmglover;
date_default_timezone_set('Australia/Brisbane');

new GitHubDeploy($_POST['payload'], '<github webhook secret key>');

class GitDeploy {
    private $repo        = '';
    private $branch      = '';
    private $head        = '';
    private $directory   = '.';

    public function __construct($repo, $head, $ref, $options = array())
    {
        $this->repo = $repo;
        $this->head = $head;
        if (file_exists('.branch')) { 
            $this->branch = file_get_contents('.branch');
        }
        $this->branch = basename($ref);
            
        if (!empty($this->branch)) {
            if ($this->branch != basename($ref)) {
                exit('Deployment aborted, deployment branch did not match enforced branch for this repository ('. $this->branch  .')');
            }
        }

        foreach ($options as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
        $this->directory = realpath($this->directory);
        self::log('Deployment Invoked.');
    }    

    protected function deploy()
    {
        // Make sure to start from the right directory.
        chdir($this->directory);
        self::log('Attempting deployment to directory: ' . getcwd());

        // Discard any changes that haven't been committed.
       self::log(shell_exec('git reset --hard HEAD'));
        self::log('Reset respository to HEAD.');

        // Update the local repository
        self::log(shell_exec('git pull origin ' . $this->branch));
        self::log('Executed git pull.');

        // Secure the .git directory
        // exec('chmod -R og-rx ' . $this->directory . ' .git');
        // parent::log('Securing .git directory... ');

        $message = sprintf('[SHA: %s] deployment of repository [%s] branch [%s] was successful', $this->head, $this->repo, $this->branch);
        self::log( $message );
        echo $message;
        return true;
    }

    public static function log($message, $type = 'INFO')
    {
        $logfile = 'deploy.log';
        if (!file_exists($logfile)) {
            // Create the log file:
            file_put_contents($logfile, '');
            // Allow anyone to write to log files
            chmod($logfile, 0666);
        }
        // Write the message into the log file
        file_put_contents($logfile, sprintf( '[%s] - %s: %s', date('Y-m-d H:i:s P'), $type, $message.PHP_EOL), FILE_APPEND);
        if ($type == 'ERROR') {
            exit('Deployment failed. Please check logs.');
        }
    }
}

class GitHubDeploy extends GitDeploy
{
    private $payload;

    public function __construct($payload, $secret = null)
    {
        $this->checkSignatureIsValid($secret);
        $this->checkReferrerIsValid();
        $this->payload = json_decode($payload);
        parent::__construct($this->payload->repository->name, $this->payload->head_commit->id, $this->payload->ref);
        /* 
         * Post-deployment tasks.
         * if ($this->deploy()) {
         *    WPDeploy::updateDb('<wp site url>');
         * }
         */
    }

    public function checkReferrerIsValid()
    {
        // Check if referrer IP is in GitHub hook server IP range.
        foreach ($this->getGithubHookIPs() as $cidr) {
            if ($this->IPinCIDR($_SERVER['REMOTE_ADDR'], $cidr)) return true;
        }
        parent::log('Referrer IP was invalid.', 'ERROR');
        return false;
    }

    public function checkSignatureIsValid($secret)
    {
        if (!isset($_SERVER['HTTP_X_HUB_SIGNATURE'])) return;
        $payloadHash = 'sha1=' . hash_hmac( 'sha1', file_get_contents("php://input"), $secret, false );
        if ($payloadHash !== $_SERVER[ 'HTTP_X_HUB_SIGNATURE' ]) {
            parent::log(sprintf('Signature did not match payload, secret is invalid. Secret SHA1 [%s], Payload SHA1 [%s]', $payloadHash, $_SERVER['HTTP_X_HUB_SIGNATURE']), 'ERROR');
        }
    }

    public function getGithubHookIPs()
    {
       // Grab GitHub hook IP's as CIDRs.
        $options = array('http' => array('user_agent' => 'php'));
        $response = json_decode(
            file_get_contents(
                'https://api.github.com/meta',
                false,
                stream_context_create($options)
            )
        );
        return $response->hooks;
    }

    public function IPinCIDR($ip, $cidr)
    {
        // Extract mask
        list($str_network, $str_mask) = array_pad(explode('/', $cidr), 2, NULL);
        if (is_null($str_mask)) {
            // No mask specified: range is a single IP address
            $mask = 0xFFFFFFFF;
        } elseif ((int)$str_mask == $str_mask) {
            // Mask is an integer: it's a bit count
            $mask = 0xFFFFFFFF << (32 - (int)$str_mask);
        } else {
            // Mask is in x.x.x.x format
            $mask = ip2long($str_mask);
        }
        $ip = ip2long($ip);
        $network = ip2long($str_network);
        $lower = $network & $mask;
        $upper = $network | (~$mask & 0xFFFFFFFF);
        return $ip >= $lower && $ip <= $upper;
    }
}

class WPDeploy extends GitDeploy
{
    public static function updateDb($WPBaseUrl)
    {
        parent::log('Updating wordpress database...');
        exec('curl ' . $WPBaseUrl . '/wp-admin/upgrade.php?step=upgrade_db');
        parent::log('Database updated');
    }
}