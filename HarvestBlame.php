<?php

/*
 * copyright (c) 2012 David Harper
 *
 * This file uses the HarvestAPI written by Matthew John Denton <matt@mdbitz.com>.
 *
 * HarvestBlame is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * HarvestBlame is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with HarvestBlame. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * HarvestBlame
 *
 * File to call the Harvest API's and generate a shame email for the 
 * last x amount of days.
 *
 * @author David Harper
 */
require_once(dirname(__FILE__) . '/HarvestAPI.php');

/* Register Auto Loader */
spl_autoload_register(array('HarvestAPI', 'autoload'));

$api = new HarvestBlame();
$api->blame();
exit();


class HarvestBlame extends HarvestAPI
{

    /**
     * @var Array Configuration settings
     */
    private $config;

    /**
     * An array storing the user's details
     * @var Array
     */
    private $users;

    /**
     * An array storing the timesheet values for the users
     * @var Array 
     */
    private $timesheets;
    
    private $start_date;
    
    private $end_date;

    /**
     * 
     */
    function __construct()
    {
        $this->config = parse_ini_file('config.ini', true);

        // Account Settings
        $this->setUser($this->getConfig('Harvest/user'));
        $this->setPassword($this->getConfig('Harvest/pass'));
        $this->setAccount($this->getConfig('Harvest/api_account'));

        // Network Settings
        $this->setRetryMode(HarvestAPI::RETRY);
        $this->setSSL((boolean) $this->getConfig('Harvest/use_ssl'));

        // Include Proxy settings if behind a Proxy Server
        if (!empty($this->config['Harvest']['http_proxy']) && !empty($this->config['Harvest']['http_proxy_port']))
        {
            $this->setProxy($this->config['Harvest']['http_proxy'], $this->config['Harvest']['http_proxy_port']);
        }

        $this->setTimezone();
        
        // Set Dates to use across functions
        $this->start_date = new DateTime(date('Y-m-d', strtotime($this->getConfig('Time/start'))));
        $this->end_date = new DateTime(date('Y-m-d', strtotime($this->getConfig('Time/end'))));
    }

    /**
     * Core function to run
     */
    public function blame()
    {
        $this->getUserDetails();
        $this->getTimesheets();
        $this->sendEmail();
    }

    /**
     * Get user objects and store them for use when generating the email
     */
    private function getUserDetails()
    {
        $this->log('Fetching user details:');
        $users = $this->getConfig('Users/user');

        if (!empty($users))
        {
            foreach ($users as $id)
            {
                // Make API calls to get user details and timesheets
                $user = $this->getUser($id);
                if ($user->isSuccess())
                {
                    $this->users[$id] = $user->data;
                    $this->log('[OK]:  ' . $id);
                }
                else
                {
                    $this->log('[FAILED]:  ' . $id);
                }
            }
        }
    }

    /**
     * Get Timesheet objects and store against the user id for use when
     * generating the email
     */
    private function getTimesheets()
    {

        $this->log('Fetching timesheets:');

        $range = new Harvest_Range($this->start_date->format('Ymd'), $this->end_date->format('Ymd'));

        if (empty($this->users))
        {
            echo 'Unable to fetch any user details, or no users are configured.';
            exit();
        }
        
        foreach ($this->users as $user)
        {
            $id = $user->get('id');

            $result = $this->getUserEntries($id, $range);

            if ($result->isSuccess())
            {
                $this->log('[OK]:' . $id);

                $timesheet = array();

                // Populate Empty Dates
                $daterange = new DatePeriod($this->start_date, new DateInterval('P1D'), $this->end_date);
                foreach ($daterange as $date)
                {
                    $timesheet[$date->format('Y-m-d')] = 0;
                }

                // Fill in real data
                foreach ($result->data as $v)
                {
                    $spent_at = $v->get('spent-at');
                    $timesheet[$spent_at] += (int) $v->get('hours');
                }

                $this->timesheets[$id] = $timesheet;
            }
            else
            {
                $this->log('[FAILED]:' . $id);
            }
        }
    }

    /**
     * Checks configuration validity and returns the value
     * @param String $option an option path such as Harvest/user
     * @return String
     */
    private function getConfig($option)
    {
        $parts = explode('/', $option);
        
        if (empty($parts[0]) || empty($parts[1])) 
        {
            return null;
        }
        
        if (!array_key_exists($parts[1], $this->config[$parts[0]]))
        {
            return null;
        }
        
        $config = $this->config[$parts[0]][$parts[1]];
        if (!isset($config))
        {
            switch ($option)
            {

                case 'Harvest/user':
                    $message = "No Harvest User has been set to connect to the API with.\n";
                    break;
                case 'Harvest/pass':
                    $message = "No Harvest Password has been set to connect to the API with.\n";
                    break;
                case 'Harvest/api_account':
                    $message = "No Harvest API Account has been set.\n";
                    break;
                case 'Harvest/use_ssl':
                    $message = "No Harvest API SSL configuration has been set.\n";
                    break;
                case 'Time/start':
                    $message = "No start time has been set for the API to look at.\n";
                    break;
                case 'Time/end':
                    $message = "No end time has been set for the API to look at.\n";
                    break;
                case 'Time/zone':
                    $message = "No timezone has been set in either php.ini or in HarvestBlame\'s config.ini.\n";
                    break;
                case 'Users/user':
                    $message = "No users have been configured.\n";
                    break;
                case 'Email/email_from':
                    $message = "No address has been configured to send mail from.\n";
                    break;
                case 'Email/email_to':
                    $message = "No address has been configured to send mail to.\n";
                    break;
                case 'Email/cc_users':
                    $message = "No configuration for whether to cc the users is set.\n";
                    break;
                default:
                    echo 'Configuration doesn\'t exist.';
            }

            echo $message;
            echo "Please refer to the config.sample.ini in the project for configuration options.\n";
            exit();
        }

        return $config;
    }

    /**
     * Send an email containing the timesheet data
     */
    private function sendEmail()
    {
        $this->log('Sending Blame Email...');

        ob_start();
        echo '<html>';

        echo '<body>';
        echo '<h1>Harvest Log</h1>';

        $subject = 'Harvest Hours for ' . $this->start_date->format('d/m/Y') . ' to ' . $this->end_date->format('d/m/Y');

        echo '<p>' . $subject . '</p>';

        echo '<table>';

        // Re-create range as it doesn't reset the counter in PHP 5.3.3
        $daterange = new DatePeriod($this->start_date, new DateInterval('P1D'), $this->end_date);
        echo '<tr><th>Name</th>';
        foreach ($daterange as $date)
        {
            echo '<th>' . $date->format('d/m') . '</th>';
        }
        echo '</tr>';

        foreach ($this->users as $k => $v)
        {
            $name = $v->get('first-name') . ' ' . $v->get('last-name');
            echo '<tr>';
            echo '<td style="text-align:right">' . $name . "</td>\n";
            foreach ($this->timesheets[$k] as $date => $hours)
            {
                switch (true)
                {
                    case (int) $hours < 4: $bg = '#A63C45';
                        $fg = '#FFF';
                        break;
                    case (int) $hours < 6: $bg = '#F2CF63';
                        $fg = '#000';
                        break;
                    default: $bg = '#88BF78';
                        $fg = '#000';
                }

                echo '<td style="text-align:center;background-color:' . $bg . ';color:' . $fg . ';">' . trim($hours) . "</td>\n";
            }
            echo '</tr>';
        }

        echo '</table>';

        echo '</body>';

        echo '</html>';

        $message = ob_get_contents();
        ob_end_clean();

        $to = $this->getConfig('Email/email_to');

        // Handle CC's
        $cc = array();
        
        $manual_cc = $this->getConfig('Email/cc');
        if (!empty($manual_cc)) {
            $cc = array_merge($cc, $manual_cc);
        }
        
        $cc_users = $this->getConfig('Email/cc_users');
        if ($cc_users == true)
        {
            foreach($this->users as $user) {
                array_push($cc, $user->get('email'));
            }
        }
        
        $headers = 
                'From:' . $this->getConfig('Email/email_from') . "\r\n" .
                'X-Mailer: PHP/' . phpversion() . "\r\n" .
                'CC:' . implode(',', $cc) . "\r\n" .
                'Content-type: text/html' . "\r\n";
        
        $sent = mail($to, $subject, $message, $headers);
        
        if ($sent === true)
        {
            $this->log('Mail sent successfully.');
        }
        else 
        {
            $this->log('Mail not successful. Check your settings.');
        }
    }

    /**
     * Looks for PHP Timezone settings and if they don't exist use the settings
     * from the configuration file.
     */
    private function setTimezone()
    {
        $timezone = ini_get('date.timezone');

        if (empty($timezone))
        {
            $zone = $this->getConfig('Time/zone');
            if (!empty($zone))
            {
                date_default_timezone_set($zone);
            }
        }
    }

    /**
     * Write a log message to the screen
     * @param String $message
     */
    private function log($message)
    {
        echo sprintf('[' . date('Y-m-d H:i:s') . ']' . " %s\n", $message);
    }

}

?>
