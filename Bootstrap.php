<?php
/**
 * Shopware 5
 * Copyright (c) shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */

/**
 */
class Shopware_Plugins_Core_SoAbandonedCartCron_Bootstrap extends Shopware_Components_Plugin_Bootstrap
{
    public function getInfo()
    {
        return array(
            'version' => $this->getVersion(),
            'label' => $this->getLabel(),
            'author' => 'Soeren Oehding',
            'supplier' => 'SO_DSGN',
            'description' => 'Automatisches versenden von Warenkorb-recovery Mails',
            'support' => 'Shopware Forum',
            'link' => 'https://so-dsgn.de'
        );
    }

    public function getLabel()
    {
        return 'SO_DSGN Warenkorb Recovery';
    }

    public function getVersion()
    {
        return "1.0.0";
    }

    public function install()
    {
        $this->registerCronJobs();

        return true;
    }

    private function registerCronJobs()
    {
        $this->createCronJob(
            'SoAbandonedCart',
            'SoAbandonedCartCron',
            60,
            true
        );

        $this->subscribeEvent(
            'Shopware_CronJob_SoAbandonedCartCron',
            'onRunAbandonedCartCron'
        );
    }

    public static function onRunAbandonedCartCron(Shopware_Components_Cron_CronJob $job)
    {

        $sql = '
            SELECT
                o.id,
                o.userID,
                o.ordernumber,
                o.ordertime,
                o.subshopID,
                u.email,
                u.lastlogin
            FROM s_order o, s_user u
            WHERE o.userId = u.id
            AND o.status = -1
            AND o.comment = ""
            AND u.active = 1
            AND o.ordertime > TIMESTAMP(DATE_SUB(NOW(), INTERVAL 2 day))
            AND u.lastlogin <= TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 hour))
        ';

        $canceledOrders = Shopware()->Db()->fetchAll($sql, array(
            '%-' . date('m-d')
        ));

        if (empty($canceledOrders)) {
            return 'No abandoned carts found.';
        }

        foreach ($canceledOrders as $order) {

            // /** @var Shopware\Models\Shop\Repository $repository  */
            $repository = Shopware()->Models()->getRepository('Shopware\Models\Shop\Shop');
            $shopId = $order['subshopID'];
            $shop = $repository->getActiveById($shopId);
            $shop->registerResources(Shopware()->Bootstrap());

            //language 	subshopID
            $context = array(
                'sUser' => $order,
                'sData' => $job['data']
            );
            
            // Send Mail
            $mail = Shopware()->TemplateMail()->createMail('sCANCELEDQUESTION', $context);
            $mail->addTo($order['email']);
            $mail->send();

            // 'Frage gesendet' marks a order, when its customer got a "Ask Reason" mail
            $orderRepository = Shopware()->Models()->getRepository('Shopware\Models\Order\Order');
            $model = $orderRepository->find($order['id']);
            $model->setComment('Frage gesendet*');
            Shopware()->Models()->flush();

            echo '<pre>';
            print_r($order);
            echo '</pre>';
            echo '<hr>';

        }

        return count($canceledOrders) . ' abandoned Cart email(s) were send.';
    }
}
