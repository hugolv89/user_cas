<?php

/**
 * ownCloud - user_cas
 *
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp <kontakt@felixrupp.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\UserCAS\Service;

use \OCP\IConfig;
use \OC\User\Session;
use \OC\User\Manager;
use \OCP\IURLGenerator;

/**
 * Class UserService
 *
 * @package OCA\UserCAS\Service
 *
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp <kontakt@felixrupp.com>
 *
 * @since 1.4.0
 */
class AppService
{

    /**
     * @var string $appName
     */
    private $appName;

    /**
     * @var \OCP\IConfig $appConfig
     */
    private $config;

    /**
     * @var \OC\User\Manager $userManager
     */
    private $userManager;

    /**
     * @var \OC\User\Session $userSession
     */
    private $userSession;

    /**
     * @var \OCP\IURLGenerator $urlGenerator
     */
    private $urlGenerator;

    /**
     * @var string
     */
    private $casVersion;

    /**
     * @var string
     */
    private $casHostname;

    /**
     * @var string
     */
    private $casPort;

    /**
     * @var string
     */
    private $casPath;

    /**
     * @var string
     */
    private $casDebugFile;

    /**
     * @var string
     */
    private $casCertPath;

    /**
     * @var string
     */
    private $casPhpFile;

    /**
     * @var string
     */
    private $casServiceUrl;

    /**
     * @var boolean
     */
    private $casInitialized;

    /**
     * UserService constructor.
     * @param $appName
     * @param \OCP\IConfig $config
     * @param \OC\User\Manager $userManager
     * @param \OC\User\Session $userSession
     * @param \OCP\IURLGenerator $urlGenerator
     */
    public function __construct($appName, IConfig $config, Manager $userManager, Session $userSession, IURLGenerator $urlGenerator)
    {

        $this->appName = $appName;
        $this->config = $config;
        $this->userManager = $userManager;
        $this->userSession = $userSession;
        $this->urlGenerator = $urlGenerator;
        $this->casInitialized = FALSE;
    }


    /**
     * init method.
     */
    public function init()
    {

        $serverHostName = (isset($_SERVER['SERVER_NAME'])) ? $_SERVER['SERVER_NAME'] : '';

        // Gather all app config values
        $this->casVersion = $this->config->getAppValue('user_cas', 'cas_server_version', '2.0');
        $this->casHostname = $this->config->getAppValue('user_cas', 'cas_server_hostname', $serverHostName);
        $this->casPort = intval($this->config->getAppValue('user_cas', 'cas_server_port', 443));
        $this->casPath = $this->config->getAppValue('user_cas', 'cas_server_path', '/cas');
        $this->casServiceUrl = $this->config->getAppValue('user_cas', 'cas_service_url', '');
        $this->casCertPath = $this->config->getAppValue('user_cas', 'cas_cert_path', '');

        $this->casDebugFile = $this->config->getAppValue('user_cas', 'cas_debug_file', '');
        $this->casPhpFile = $this->config->getAppValue('user_cas', 'cas_php_cas_path', '');

        if (is_string($this->casPhpFile) && strlen($this->casPhpFile) > 0) {

            \OCP\Util::writeLog('cas', 'Use custom phpCAS file:: ' . $this->casPhpFile, \OCP\Util::DEBUG);

            require_once("$this->casPhpFile");
        } else {

            require_once(__DIR__ . '/../../vendor/jasig/phpcas/CAS.php');
        }

        if (!class_exists('\\phpCAS')) {

            \OCP\Util::writeLog('cas', 'phpCAS library could not be loaded. The class was not found.', \OCP\Util::ERROR);
        }

        if (!\phpCAS::isInitialized()) {

            try {

                \phpCAS::setVerbose(TRUE);

                if (!empty($this->casDebugFile)) {

                    \phpCAS::setDebug($this->casDebugFile);
                }


                # Initialize client
                \phpCAS::client($this->casVersion, $this->casHostname, intval($this->casPort), $this->casPath);


                if (!empty($this->casServiceUrl)) {

                    \phpCAS::setFixedServiceURL($this->casServiceUrl);
                }

                if (!empty($this->casCertPath)) {

                    \phpCAS::setCasServerCACert($this->casCertPath);
                } else {

                    \phpCAS::setNoCasServerValidation();
                }

                $this->casInitialized = TRUE;

                \OCP\Util::writeLog('cas', "phpCAS has been successfully initialized.", \OCP\Util::DEBUG);

            } catch (\CAS_Exception $e) {

                $this->casInitialized = FALSE;

                \OCP\Util::writeLog('cas', "phpCAS has thrown an exception with code: " . $e->getCode() . " and message: " . $e->getMessage() . ".", \OCP\Util::ERROR);
            }
        } else {

            $this->casInitialized = TRUE;

            \OCP\Util::writeLog('cas', "phpCAS has already been initialized.", \OCP\Util::DEBUG);
        }
    }

    /**
     * Check if login should be enforced using user_cas.
     *
     * @return bool TRUE|FALSE
     */
    public function isEnforceAuthentication()
    {
        if (\OC::$CLI) {
            return FALSE;
        }

        if ($this->config->getAppValue($this->appName, 'cas_force_login') !== '1') {
            return FALSE;
        }

        if ($this->userSession->isLoggedIn()) {
            return FALSE;
        }


        $script = $_SERVER['SCRIPT_FILENAME'];
        if (in_array(basename($script), array('console.php', 'cron.php', 'public.php', 'remote.php', 'status.php', 'version.php')) || strpos($script, "/ocs")) {
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Create a link to $route with URLGenerator.
     *
     * @param string $route
     * @param array $arguments
     * @return string
     */
    public function linkToRoute($route, $arguments = array())
    {

        return $this->urlGenerator->linkToRoute($route, $arguments);
    }

    /**
     * Create an absolute link to $route with URLGenerator.
     *
     * @param string $route
     * @param array $arguments
     * @return string
     */
    public function linkToRouteAbsolute($route, $arguments = array())
    {

        return $this->urlGenerator->linkToRouteAbsolute($route, $arguments);
    }

    /**
     * Create an url relative to owncloud host
     *
     * @param string $url
     * @return mixed
     */
    public function getAbsoluteURL($url)
    {

        return $this->urlGenerator->getAbsoluteURL($url);
    }

    /**
     * @return boolean
     */
    public function isCasInitialized()
    {
        return $this->casInitialized;
    }

    /**
     * @return array
     */
    public function getCasHosts()
    {

        return explode(";", $this->casHostname);
    }


    ## Setters/Getters

    /**
     * @return string
     */
    public function getAppName()
    {
        return $this->appName;
    }

    /**
     * @param string $appName
     */
    public function setAppName($appName)
    {
        $this->appName = $appName;
    }

    /**
     * @return string
     */
    public function getCasVersion()
    {
        return $this->casVersion;
    }

    /**
     * @param string $casVersion
     */
    public function setCasVersion($casVersion)
    {
        $this->casVersion = $casVersion;
    }

    /**
     * @return string
     */
    public function getCasHostname()
    {
        return $this->casHostname;
    }

    /**
     * @param string $casHostname
     */
    public function setCasHostname($casHostname)
    {
        $this->casHostname = $casHostname;
    }

    /**
     * @return string
     */
    public function getCasPort()
    {
        return $this->casPort;
    }

    /**
     * @param string $casPort
     */
    public function setCasPort($casPort)
    {
        $this->casPort = $casPort;
    }

    /**
     * @return string
     */
    public function getCasPath()
    {
        return $this->casPath;
    }

    /**
     * @param string $casPath
     */
    public function setCasPath($casPath)
    {
        $this->casPath = $casPath;
    }

    /**
     * @return string
     */
    public function getCasDebugFile()
    {
        return $this->casDebugFile;
    }

    /**
     * @param string $casDebugFile
     */
    public function setCasDebugFile($casDebugFile)
    {
        $this->casDebugFile = $casDebugFile;
    }

    /**
     * @return string
     */
    public function getCasCertPath()
    {
        return $this->casCertPath;
    }

    /**
     * @param string $casCertPath
     */
    public function setCasCertPath($casCertPath)
    {
        $this->casCertPath = $casCertPath;
    }

    /**
     * @return string
     */
    public function getCasPhpFile()
    {
        return $this->casPhpFile;
    }

    /**
     * @param string $casPhpFile
     */
    public function setCasPhpFile($casPhpFile)
    {
        $this->casPhpFile = $casPhpFile;
    }

    /**
     * @return string
     */
    public function getCasServiceUrl()
    {
        return $this->casServiceUrl;
    }

    /**
     * @param string $casServiceUrl
     */
    public function setCasServiceUrl($casServiceUrl)
    {
        $this->casServiceUrl = $casServiceUrl;
    }
}