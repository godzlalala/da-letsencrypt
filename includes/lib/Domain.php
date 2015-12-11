<?php

namespace DirectAdmin\LetsEncrypt\Lib;

use Crypt_RSA;
use DirectAdmin\LetsEncrypt\Lib\Utility\ConfigurableTrait;
use DirectAdmin\LetsEncrypt\Lib\Utility\StorageTrait;
use Kelunik\Acme\KeyPair;

/**
 * Class Domain
 *
 * @package DirectAdmin\LetsEncrypt\Lib
 */
class Domain {

    use ConfigurableTrait;

    private $domain;
    private $subdomains;

    private $certificates;

    /** @var  KeyPair */
    public $domainKeys;
    public $account;

    /**
     * Initialize a domain with subdomains
     *
     * @param String $domain Domain name
     * @param Account|null $account Account
     */
    function __construct($domain, $account = null) {
        $this->domain = $domain;
        $this->account = $account;
        $this->subdomains = $this->receiveSubdomains();
    }

    /**
     * Create domain RSA keys
     *
     * @return KeyPair
     * @throws \Exception
     */
    public function createKeys() {
        $rsa = new Crypt_RSA();

        $keys = $rsa->createKey(4096);

        if ($keys['partialkey'] === false) {
            $this->domainKeys = new KeyPair($keys['privatekey'], $keys['publickey']);
        } else {
            throw new \Exception('CPU was to slow, we\'ve not yet coded this part.');
        }

        return $this->domainKeys;
    }

    /**
     * Request certificate at ACME
     *
     * @param KeyPair|null $domainKeys
     * @param array|null $subdomains List of subdomains to request
     * @return array
     * @throws \Exception
     * @throws \Kelunik\Acme\AcmeException
     */
    public function requestCertificate($domainKeys = null, $subdomains = null) {
        if ($domainKeys == null) {
            if ($this->domainKeys == null) {
                $this->createKeys();
            } else {
                $domainKeys = $this->domainKeys;
            }
        }

        $domains = (array) $this->getDomain();

        if ($subdomains == null) {
            $domains += $this->getSubdomains();
        } else {
            $domains += $subdomains;
        }

        $location = $this->account->acme->requestCertificate($domainKeys, $domains);
        $this->certificates = $this->account->acme->pollForCertificate($location);

        return $this->certificates;
    }

    /**
     * Apply certificates to DirectAdmin
     *
     * @return bool
     * @throws \Exception
     */
    public function applyCertificates() {
        if (defined('CRON')) {
            $domainPath = '/usr/local/directadmin/data/users/' . $this->account->getUsername() . '/domains/' . $this->getDomain();

            file_put_contents($domainPath . '.key', $this->domainKeys->getPrivate());
            chown($domainPath . '.key', 'diradmin');
            chgrp($domainPath . '.key', 'diradmin');
            chmod($domainPath . '.key', 600);

            file_put_contents($domainPath . '.cert', $this->getCertificate());
            chown($domainPath . '.cert', 'diradmin');
            chgrp($domainPath . '.cert', 'diradmin');
            chmod($domainPath . '.cert', 600);

            file_put_contents($domainPath . '.cacert', implode("\n", $this->getCertificateAuthorityCertificates()));
            chown($domainPath . '.cacert', 'diradmin');
            chgrp($domainPath . '.cacert', 'diradmin');
            chmod($domainPath . '.cacert', 600);

            $configString = file_get_contents($domainPath . '.conf');

            $config = array();
            foreach (explode("\n", $configString) as $configLine) {
                if (empty($configLine)) {
                    continue;
                }

                list($configKey, $configValue) = explode('=', $configLine, 2);

                $config[$configKey] = $configValue;
            }

            $config['SSLCertificateKeyFile'] = $domainPath . '.key';
            $config['SSLCertificateFile'] = $domainPath . '.cert';
            $config['SSLCACertificateFile'] = $domainPath . '.cacert';
            $config['ssl'] = 'ON';

            $configString = '';

            foreach ($config as $configKey => $configValue) {
                $configString .= $configKey . '=' . $configValue . PHP_EOL;
            }

            file_put_contents($domainPath . '.conf', $configString);
        } else {
            $sock = new HTTPSocket();
            $sock->connect('127.0.0.1', 2222);
            $sock->set_login('admin');
            $sock->set_method('POST');
            $sock->query('/CMD_API_SSL', [
                'domain' => $this->getDomain(),
                'action' => 'save',
                'type' => 'paste',
                'certificate' => $this->domainKeys->getPrivate() . PHP_EOL . $this->getCertificate(),
                'submit' => 'Save'
            ]);
            $result = $sock->fetch_parsed_body();

            if ($result['error'] != 0) {
                throw new \Exception('Error while executing first API request: ' . $result['details']);
            }

            $sock = new HTTPSocket();
            $sock->connect('127.0.0.1', 2222);
            $sock->set_login('admin');
            $sock->set_method('POST');
            $sock->query('/CMD_API_SSL', [
                'domain' => $this->getDomain(),
                'action' => 'save',
                'type' => 'cacert',
                'active' => 'yes',
                'cacert' => implode("\n", $this->getCertificateAuthorityCertificates()),
                'submit' => 'Save'
            ]);
            $result = $sock->fetch_parsed_body();

            if ($result['error'] != 0) {
                throw new \Exception('Error while executing second API request: ' . $result['details']);
            }
        }

        return true;
    }

    /**
     * Get the domains certificate
     *
     * @return string
     */
    public function getCertificate() {
        return trim($this->certificates[0]);
    }

    /**
     * Get a array of certificate authority certificates
     *
     * @return string[]
     */
    public function getCertificateAuthorityCertificates() {
        return array_map('trim', array_slice($this->certificates, 1));
    }

    /**
     * Get domain's name
     *
     * @return String
     */
    public function getDomain() {
        return $this->domain;
    }

    /**
     * Get list of subdomains
     *
     * @return Array
     */
    public function getSubdomains() {
        return $this->subdomains;
    }

    /**
     * Receive available subdomains from Directadmin
     *
     * @return Array
     */
    public function receiveSubdomains() {
        if (defined('CRON')) {
            $subdomainsFile = file_get_contents('/usr/local/directadmin/data/users/' . $this->account->getUsername() . '/domains/' . $this->getDomain() . '.subdomains');

            $subdomains = ['www.' . $this->getDomain()];

            foreach (explode("\n", $subdomainsFile) as $subdomain) {
                $subdomains[] = $subdomain . '.' . $this->getDomain();
                $subdomains[] = 'www.' . $subdomain . '.' . $this->getDomain();
            }
        } else {
            $sock = new HTTPSocket();
            $sock->connect('127.0.0.1', 2222);
            $sock->set_login('admin');
            $sock->set_method('POST');
            $sock->query('/CMD_API_SUBDOMAIN', [
                'domain' => $_SERVER['SESSION_SELECTED_DOMAIN']
            ]);
            $result = $sock->fetch_parsed_body();

            $subdomains = ['www.' . $this->getDomain()];

            foreach ($result['list'] as $subdomain) {
                $subdomains[] = $subdomain . '.' . $this->getDomain();
                $subdomains[] = 'www.' . $subdomain . '.' . $this->getDomain();
            }
        }

        return $subdomains;
    }

    /**
     * Get path to domain root
     *
     * @return string
     */
    public function getPath() {
        return DIRECTORY_SEPARATOR . 'home' . DIRECTORY_SEPARATOR . $this->account->getUsername() . DIRECTORY_SEPARATOR . 'domains' . DIRECTORY_SEPARATOR . $this->getDomain();
    }

    /**
     * Get path to domain storage
     *
     * @return string
     */
    public function getStoragePath() {
        return $this->getPath() . DIRECTORY_SEPARATOR . '.letsencrypt';
    }

    function __debugInfo() {
        return [
            'account' => $this->account,
            'domain' => $this->domain
        ];
    }
}
