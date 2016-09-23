<?php
/**
 * @package       RSMembership!
 * @copyright (C) 2009-2014 www.rsjoomla.com
 * @license       GPL, http://www.gnu.org/licenses/gpl-2.0.html
 */
/**
 * @plugin RSMembership NextPay Payment
 * @author @nextpay
 * @authorEmail info@nextpay.ir
 * @authorUrl http://nextpay.ir
 */

ini_set('display_errors', 1);
defined('_JEXEC') or die('Restricted access');
require_once JPATH_ADMINISTRATOR . '/components/com_rsmembership/helpers/rsmembership.php';

class plgSystemRSMembershipZarinPal extends JPlugin
{
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        // load languages
        $this->loadLanguage('plg_system_rsmembership', JPATH_ADMINISTRATOR);
        $this->loadLanguage('plg_system_rsmembershipnextpay', JPATH_ADMINISTRATOR);

        RSMembership::addPlugin($this->translate('nice_name'), 'rsmembershipnextpay');
    }

    /**
     * call when payment starts
     *
     * @param $plugin
     * @param $data
     * @param $extra
     * @param $membership
     * @param $transaction
     * @param $html
     */
    public function onMembershipPayment($plugin, $data, $extra, $membership, $transaction, $html)
    {
        $app = JFactory::getApplication();
        try {
            if ($plugin != 'rsmembershipnextpay')
                return;

            include_once ('nextpay_payment.php');

            $api_key = trim($this->params->get('api_key'));

            $extra_total = 0;
            foreach ($extra as $row) {
                $extra_total += $row->price;
            }

            $Amount = $transaction->price + $extra_total;
            $order_id = time();
            $Description = $membership->name;
            $Description = $this->escape($Description);

            $Email = $data->email;
            $Mobile = '';

            $transaction->custom = md5($transaction->params . ' ' . $order_id);
            if ($membership->activation == 2) {
                $transaction->status = 'completed';
            }
            $transaction->store();

            $callback_uri = JURI::base() . 'index.php?option=com_rsmembership&nextpayPayment=1&amount=' . $Amount;
            $callback_uri = JRoute::_($callback_uri, false);
            $session =& JFactory::getSession();
            $session->set('transaction_custom', $transaction->custom);
            $session->set('membership_id', $membership->id);

            $params = compact(
                'api_key',
                'order_id',
                'amount',
                'callback_uri'
            );

            $nextpay = new Nextpay_Payment($params);

            $result = $nextpay->token();

            if(intval($result->code) == -1) {
                //$nextpay->send($result->trans_id);
                $app->redirect("http://api.nextpay.org/payment/{$result->trans_id}");
            } else {
                throw new Exception('connection_error');
            }

        } catch (Exception $e) {
            $message = $this->translate('error_title') . '<br>' . $this->translate($e->getMessage());
            $app->redirect(JRoute::_(JURI::base() . 'index.php/component/rsmembership/view-membership-details/' . $membership->id, false), $message, 'warning');
            exit;
        }
    }


    /**
     * after payment completed
     * calls function onPaymentNotification()
     */
    public function onAfterDispatch()
    {
        $app = JFactory::getApplication();
        if ($app->input->getBoolean('nextpayPayment')) {
            $this->onPaymentNotification($app);
        }
    }

    /**
     * process payment verification and approve subscription
     * @param $app
     */
    protected function onPaymentNotification($app)
    {
        $input = $app->input;
        $session =& JFactory::getSession();

        $transaction_custom = $session->get('transaction_custom');

        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('*')
            ->from($db->quoteName('#__rsmembership_transactions'))
            ->where($db->quoteName('status') . ' != ' . $db->quote('completed'))
            ->where($db->quoteName('custom') . ' = ' . $db->quote($transaction_custom));
        $db->setQuery($query);
        $transaction = @$db->loadObject();

        try {
            if (!$transaction)
                throw new Exception('transaction_not_found', 1);

            if (!$this->params->get('trans_id'))
                throw new Exception('payment_failed');

            $trans_id = $this->params->get('trans_id');
            $order_id = $this->params->get('order_id');
            $api_key = $input->getString('api_key');
            $amount = $input->getInt('amount');

            include_once ('nextpay_payment.php');

            $params = compact('trans_id', 'api_key', 'order_id', 'amount');
            $nextpay = new Nextpay_Payment($params);

            $verify = $nextpay->verify_request($params);

            if (!$verify || intval($verify) != 0)
                throw new Exception('connection_error');

            $status = $verify;

            if ($status == 0) {
                $RefID = $trans_id;

                $query->clear();
                $query->update($db->quoteName('#__rsmembership_transactions'))
                    ->set($db->quoteName('hash') . ' = ' . $db->quote($RefID))
                    ->where($db->quoteName('id') . ' = ' . $db->quote($transaction->id));

                $db->setQuery($query);
                $db->execute();

                $membership_id = $session->get('membership_id');

                if (!$membership_id)
                    throw new Exception('membership_not_found');

                $query->clear()
                    ->select('activation')
                    ->from($db->quoteName('#__rsmembership_memberships'))
                    ->where($db->quoteName('id') . ' = ' . $db->quote((int)$membership_id));
                $db->setQuery($query);
                $activation = $db->loadResult();

                if ($activation) // activation == 0 => activation is manual
                {
                    RSMembership::approve($transaction->id);
                }

                $message = sprintf($this->translate('payment_succeed'), $RefID);

                $app->redirect(JRoute::_(JURI::base() . 'index.php?option=com_rsmembership&view=mymemberships', false), $message, 'message');
                die();
            }

            throw new Exception('status_' . $status);

        } catch (Exception $e) {
            if (!$e->getCode()) // means transaction found but should be denied
            {
                RSMembership::deny($transaction->id);
            }

            $message = $this->translate('error_title') . '<br>' . $this->translate($e->getMessage());
            $app->enqueueMessage($message, 'warning');
        }
    }

    /**
     * escape string
     * @param $string
     *
     * @return string
     */
    protected function escape($string)
    {
        return htmlentities($string, ENT_COMPAT, 'utf-8');
    }

    /**
     * translate plugin language files
     * @param $key
     * @return mixed
     */
    protected function translate($key)
    {
        $key = 'NEXTPAY_' . strtoupper($key);
        return JText::_($key);
    }
}