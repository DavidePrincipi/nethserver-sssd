<?php

namespace NethServer\Module\SssdConfig\Wizard;

/*
 * Copyright (C) 2017 Nethesis S.r.l.
 * http://www.nethesis.it - nethserver@nethesis.it
 *
 * This script is part of NethServer.
 *
 * NethServer is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or any later version.
 *
 * NethServer is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with NethServer.  If not, see COPYING.
 */

use Nethgui\System\PlatformInterface as Validate;

/**
 *
 * @author Davide Principi <davide.principi@nethesis.it>
 */
class AdJoinMember extends \Nethgui\Controller\AbstractController  implements \Nethgui\Component\DependencyConsumer
{

    private $joinError;

    public function initialize()
    {
        parent::initialize();
        $workgroupValidator = $this->createValidator(Validate::HOSTNAME_SIMPLE)->maxLength(15);
        $realmValidator = $this->createValidator(Validate::HOSTNAME_FQDN);

        $this->declareParameter('AdDns', Validate::IP_OR_EMPTY);
        $this->declareParameter('AdRealm', $realmValidator);
        $this->declareParameter('AdUsername', Validate::NOTEMPTY);
        $this->declareParameter('AdPassword', Validate::NOTEMPTY);
    }

    public function validate(\Nethgui\Controller\ValidationReportInterface $report)
    {
        parent::validate($report);
        if($report->hasValidationErrors() || ! $this->getRequest()->isMutation()) {
            return;
        }

        $dnsValidator = $this->createValidator()->platform('ad-dns', strtolower($this->parameters['AdRealm']));
        if( ! $dnsValidator->evaluate($this->parameters['AdDns'])) {
            $report->addValidationError($this, 'AdDns', $dnsValidator);
        }
    }

    public function process()
    {
        parent::process();
        if ( ! $this->getRequest()->isMutation()) {
            return;
        }

        $this->getPlatform()->signalEvent('nethserver-sssd-leave');

        $configDb = $this->getPlatform()->getDatabase('configuration');
        $realm = strtoupper($this->parameters['AdRealm']);
        $addns = $this->parameters['AdDns'];

        $probead = json_decode($this->getPlatform()->exec('/usr/sbin/account-provider-test probead ${@}', array($realm, $addns))->getOutput(), TRUE);
        if( ! is_array($probead)) {
            $this->getLog()->error('AD probe failed!');
            $probead = array();
        }

        if($realm != $probead['Realm']) {
            $this->getLog()->warning("Probed AD domain ({$probead['Realm']}) is different from the given one ($realm)!");
        }

        $configDb->setProp('sssd', array(
            'status' => 'disabled',
            'Realm' => $realm,
            'Workgroup' => '',
            'AdDns' => $addns,
            'Provider' => 'ad',
            'LdapURI' => $probead['LdapURI'],
            'StartTls' => $probead['StartTls'] ? 'enabled' : 'disabled',
            'UserDN' => $probead['UserDN'],
            'GroupDN' => $probead['GroupDN'],
            'BaseDN' => $probead['BaseDN'],
            'BindDN' => '',
            'BindPassword' => '',
        ));

        if($addns) {
            $this->getLog()->notice(sprintf('Enable AD DNS %s', $addns));
            $this->getPlatform()->signalEvent('nethserver-dnsmasq-save');
        }

        sleep(1);

        $probeworkgroup = json_decode($this->getPlatform()->exec('/usr/sbin/account-provider-test probeworkgroup ${@}', array($realm))->getOutput(), TRUE);
        if(is_array($probeworkgroup) && $probeworkgroup['Workgroup']) {
            $configDb->setProp('sssd', array('Workgroup' => $probeworkgroup['Workgroup']));
        } else {
            $this->getLog()->error('Workgroup probe failed!');
        }

        $descriptors = array(array('pipe', 'r'), array('pipe', 'w'));
        $pipes = array();
        $ph = proc_open(sprintf('/usr/bin/sudo /usr/sbin/realm join --server-software=active-directory -v -U %s %s 2>&1', escapeshellarg($this->parameters['AdUsername']), escapeshellarg($realm)), $descriptors, $pipes);
        if(is_resource($ph)) {
            fwrite($pipes[0], $this->parameters['AdPassword'] . "\n");
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $err = proc_close($ph);
        } else {
            $err = 255;
            $output = print_r(error_get_last(), TRUE);
        }

        if ($err === 0) {

            $this->getLog()->notice("Active Directory domain $realm was joined succesfully!");
            $this->getLog()->notice("NetBIOS domain name is " . $probeworkgroup['Workgroup']);
            $configDb->setProp('sssd', array('status' => 'enabled'));
            $this->getPlatform()->signalEvent('nethserver-sssd-save');

        } else {

            $this->joinError = implode("\n", preg_filter(array('/^ ! /'), array(), explode("\n", $output)));
            $configDb->setProp('sssd', array(
                'status' => 'disabled',
                'Realm' => '',
                'Workgroup' => '',
                'AdDns' => '',
                'Provider' => 'none',
                'LdapURI' => '',
                'StartTls' => '',
                'UserDN' => '',
                'GroupDN' => '',
                'BaseDN' => '',
                'BindDN' => '',
                'BindPassword' => '',
            ));
            $this->getPlatform()->signalEvent('nethserver-sssd-leave');
            if($addns) {
                $this->getPlatform()->signalEvent('nethserver-dnsmasq-save');
            }
            $this->getLog()->error("Exit code from realm join operation is $err");
        }
    }

    public function prepareView(\Nethgui\View\ViewInterface $view)
    {
        parent::prepareView($view);
        $view['Back'] = $view->getModuleUrl('../Ad');
        if($this->getRequest()->isValidated()) {
            $view->getCommandList()->show();
            if($this->getRequest()->isMutation()) {
                if (isset($this->joinError)) {
                    $this->notifications->error($view->translate('AdJoinMemberError_label', array($this->joinError)));
                }
            } else {
                // Fill the form with default values
                $view['AdRealm'] = strtolower($this->getPlatform()->getDatabase('configuration')->getType('DomainName'));
                $view['AdUsername'] = 'administrator';
            }
        }
    }

    public function nextPath()
    {
        if($this->getRequest()->isMutation() && ! isset($this->joinError)) {
            return '../RemoteAdProvider?bindSuccess';
        }
        return FALSE;
    }

    public function setUserNotifications(\Nethgui\Model\UserNotifications $n)
    {
        $this->notifications = $n;
        return $this;
    }

    public function getDependencySetters()
    {
        return array('UserNotifications' => array($this, 'setUserNotifications'));
    }

}
