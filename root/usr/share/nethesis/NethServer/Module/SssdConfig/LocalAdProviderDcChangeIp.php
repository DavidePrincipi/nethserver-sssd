<?php

namespace NethServer\Module\SssdConfig;

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
class LocalAdProviderDcChangeIp extends \Nethgui\Controller\AbstractController {

    public function initialize()
    {
        parent::initialize();
        $this->declareParameter('AdIpAddress', $this->createValidator(Validate::IP)->platform('dcipaddr'), array('configuration', 'nsdc', 'IpAddress'));
    }

    public function process()
    {
        parent::process();
        if($this->getRequest()->isMutation()) {
            $this->getPlatform()->signalEvent('nethserver-dc-change-ip &', array($this->parameters['AdIpAddress']));
        }
    }

    public function prepareView(\Nethgui\View\ViewInterface $view)
    {
        parent::prepareView($view);

        if($this->getRequest()->isValidated()) {
            if($this->getRequest()->isMutation()) {
                $view->getCommandList()->hide();
                $this->getPlatform()->setDetachedProcessCondition('success', array(
                    'location' => array(
                        'url' => $view->getModuleUrl('/SssdConfig/LocalAdProvider?dcChangeIpSuccess'),
                        'freeze' => TRUE,
                )));
                $this->getPlatform()->setDetachedProcessCondition('failure', array(
                    'location' => array(
                        'url' => $view->getModuleUrl('/SssdConfig/LocalAdProvider?dcChangeIpFailure&taskId={taskId}'),
                        'freeze' => TRUE,
                )));
            } else {
                $view->getCommandList()->show();
            }
        }

        $elements = json_decode($this->getPlatform()->exec('/usr/libexec/nethserver/trusted-networks')->getOutput(), TRUE);
        $greenList = array();
        if(is_array($elements)) {
            foreach($elements as $elem) {
                if($elem['provider'] === 'green') {
                    $greenList[] = $elem['cidr'];
                }
            }
        }
        $view['greenList'] = implode(', ', array_unique($greenList));
    }
}
