<?php
use DirectAdmin\LetsEncrypt\Lib\Account;
use DirectAdmin\LetsEncrypt\Lib\Challenges;
use DirectAdmin\LetsEncrypt\Lib\Config;
use DirectAdmin\LetsEncrypt\Lib\Domain;
use DirectAdmin\LetsEncrypt\Lib\Logger;

define('CRON', true);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$log = new Logger();
$config = new Config();

$usersPath = '/usr/local/directadmin/data/users/';

// Get all users
$users = scandir($usersPath);

// Loop through all users
foreach ($users as $user) {
    // Check if it's not some junk thingy
    if (in_array($user, ['.', '..']) || empty($user)) {
        continue;
    }

    // Create account object
    $account = new Account($user, null, $config->config('server'));

    // Is there a config file present?
    if (!$account->existsInStorage('config.json')) {
        $log->log('Skipped user ' . $account->getUsername());

        continue;
    }

    $log->log('Processing user ' . $account->getUsername());

    if (!$account->loadKeys()) {
        $log->log('No keys present at user ' . $account->getUsername());

        continue;
    }

    $account->setEmail($account->config('email'));

    // Get all domains of the user
    $domains = file_get_contents($usersPath . DIRECTORY_SEPARATOR . $account->getUsername() . DIRECTORY_SEPARATOR . 'domains.list');

    // Loop through all domains of the user
    foreach (explode("\n", $domains) as $domain) {
        if (empty($domain)) {
            continue;
        }

        // Replace the $domain with our Domain object,
        $domain = new Domain($domain, $account);

        if (!$domain->existsInStorage('config.json')) {
            $log->log('Skipped domain ' . $domain->getDomain());

            continue;
        }

        $log->log('Processing domain ' . $domain->getDomain());

        // Check if a renew is required, if everything needs to be checked within 10 days or in the past
        if (strtotime($domain->config('expire')) - time() >= 10 * 86400) {
            $log->log('Domain ' . $domain->getDomain() . ' doesn\'t need a reissue');

            continue;
        }

        try {
            $challenges = new Challenges($domain, $domain->config('subdomains'));
            $challenges->solveChallenge();

            $log->log('Successfully completed challenge for ' . $domain->getDomain());

            $domain->createKeys();
            $domain->requestCertificate(null, $domain->config('subdomains'));

            $log->log('Successfully received certificate from Let\'s Encrypt');

            $domain->applyCertificates();

            $log->log('Successfully applied certificate and CA certificates to DirectAdmin');

            $domain->config('domain', $domain->getDomain());
            $domain->config('subdomains', $domain->getSubdomains());

            $domain->config('status', 'applied to DirectAdmin (renewed)');
            $domain->config('expire', date('Y-m-d', strtotime('+50 days')));

            $log->log('Reissued domain ' . $domain->getDomain() . ' with success.');
        } catch(\Exception $e) {
            $log->error($e->getMessage(), null, false);
        }
    }
}

$tasks = [];

// Rewrite HTTPD files
$tasks[] = 'action=rewrite&value=httpd';

$log->log('Added rewrite and reload to Task.queue');

// Send notification to admin
$lines = [];
$lines[] = 'First of all, thanks for using our plugin! Now, let\'s get to straight business, the reissue cron has just ran!';
$lines[] = '';
$lines[] = 'Here a list of everything that happend:';
foreach ($log->getLog() as $line) {
    $lines[] = $line;
}

$tasks[] = 'action=notify&value=admin&subject=Lets Encrypt reissue cron ran&from=da-letsencrypt&message=' . urlencode(implode(PHP_EOL, $lines));

file_put_contents('/usr/local/directadmin/data/task.queue', implode(PHP_EOL, $tasks) . PHP_EOL, FILE_APPEND);
