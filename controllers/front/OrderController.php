<?php
/**
 * 2007-2017 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2017 PrestaShop SA
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */
use PrestaShop\PrestaShop\Core\Foundation\Templating\RenderableProxy;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;

class OrderControllerCore extends FrontController
{
    public $ssl = true;
    public $php_self = 'order';
    public $page_name = 'checkout';

    private $checkoutProcess;

    public function init()
    {
        parent::init();
        $this->cartChecksum = new CartChecksum(new AddressChecksum());
    }

    public function postProcess()
    {
        parent::postProcess();

        if (Tools::isSubmit('submitReorder') && $id_order = (int) Tools::getValue('id_order')) {
            $oldCart = new Cart(Order::getCartIdStatic($id_order, $this->context->customer->id));
            $duplication = $oldCart->duplicate();
            if (!$duplication || !Validate::isLoadedObject($duplication['cart'])) {
                $this->errors[] = $this->trans('Sorry. We cannot renew your order.', array(), 'Shop.Notifications.Error');
            } elseif (!$duplication['success']) {
                $this->errors[] = $this->trans(
                    'Some items are no longer available, and we are unable to renew your order.', array(), 'Shop.Notifications.Error'
                );
            } else {
                $this->context->cookie->id_cart = $duplication['cart']->id;
                $context = $this->context;
                $context->cart = $duplication['cart'];
                CartRule::autoAddToCart($context);
                $this->context->cookie->write();
                Tools::redirect('index.php?controller=order');
            }
        }

        $this->bootstrap();
    }

    private function getCheckoutSession()
    {
        $deliveryOptionsFinder = new DeliveryOptionsFinder(
            $this->context,
            $this->getTranslator(),
            $this->objectPresenter,
            new PriceFormatter()
        );

        $session = new CheckoutSession(
            $this->context,
            $deliveryOptionsFinder
        );

        return $session;
    }

    private function bootstrap()
    {
        $translator = $this->getTranslator();

        $session = $this->getCheckoutSession();

        $this->checkoutProcess = new CheckoutProcess(
            $this->context,
            $session
        );

        $this->checkoutProcess
            ->addStep(new CheckoutPersonalInformationStep(
                $this->context,
                $translator,
                $this->makeLoginForm(),
                $this->makeCustomerForm()
            ))
            ->addStep(new CheckoutAddressesStep(
                $this->context,
                $translator,
                $this->makeAddressForm()
            ));

        if (!$this->context->cart->isVirtualCart()) {
            $checkoutDeliveryStep = new CheckoutDeliveryStep(
                $this->context,
                $translator
            );

            $checkoutDeliveryStep
                ->setRecyclablePackAllowed((bool) Configuration::get('PS_RECYCLABLE_PACK'))
                ->setGiftAllowed((bool) Configuration::get('PS_GIFT_WRAPPING'))
                ->setIncludeTaxes(
                    !Product::getTaxCalculationMethod((int) $this->context->cart->id_customer)
                    && (int) Configuration::get('PS_TAX')
                )
                ->setDisplayTaxesLabel((Configuration::get('PS_TAX') && !Configuration::get('AEUC_LABEL_TAX_INC_EXC')))
                ->setGiftCost(
                    $this->context->cart->getGiftWrappingPrice(
                        $checkoutDeliveryStep->getIncludeTaxes()
                    )
                );

            $this->checkoutProcess->addStep($checkoutDeliveryStep);
        }

        $this->checkoutProcess
            ->addStep(new CheckoutPaymentStep(
                $this->context,
                $translator,
                new PaymentOptionsFinder(),
                new ConditionsToApproveFinder(
                    $this->context,
                    $translator
                )
            ))
        ;
    }

    private function saveDataToPersist(CheckoutProcess $process)
    {
        $data = $process->getDataToPersist();
        $data['checksum'] = $this->cartChecksum->generateChecksum($this->context->cart);

        Db::getInstance()->execute(
            'UPDATE '._DB_PREFIX_.'cart SET checkout_session_data = "'.pSQL(json_encode($data)).'"
                WHERE id_cart = '.(int) $this->context->cart->id
        );
    }

    private function restorePersistedData(CheckoutProcess $process)
    {
        $rawData = Db::getInstance()->getValue(
            'SELECT checkout_session_data FROM '._DB_PREFIX_.'cart WHERE id_cart = '.(int) $this->context->cart->id
        );
        $data = json_decode($rawData, true);
        if (!is_array($data)) {
            $data = [];
        }

        $checksum = $this->cartChecksum->generateChecksum($this->context->cart);
        if (isset($data['checksum']) && $data['checksum'] === $checksum) {
            $process->restorePersistedData($data);
        }
    }

    public function displayAjaxselectDeliveryOption()
    {
        $cart = $this->cart_presenter->present(
            $this->context->cart
        );

        ob_end_clean();
        header('Content-Type: application/json');
        $this->ajaxDie(Tools::jsonEncode(array(
            'preview' => $this->render('checkout/_partials/cart-summary', array(
                'cart' => $cart,
                'static_token' => Tools::getToken(false),
            ))
        )));
    }

    public function initContent()
    {
        if (Configuration::isCatalogMode()) {
            Tools::redirect('index.php');
        }

        parent::initContent();

        $this->restorePersistedData($this->checkoutProcess);
        $this->checkoutProcess->handleRequest(
            Tools::getAllValues()
        );

        $presentedCart = $this->cart_presenter->present($this->context->cart);

        if (count($presentedCart['products']) <= 0 || $presentedCart['minimalPurchaseRequired']) {
            Tools::redirect('index.php?controller=cart');
        }

        $this->checkoutProcess
            ->setNextStepReachable()
            ->markCurrentStep()
            ->invalidateAllStepsAfterCurrent();

        $this->saveDataToPersist($this->checkoutProcess);

        if (!$this->checkoutProcess->hasErrors()) {
            if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !$this->ajax) {
                return $this->redirectWithNotifications(
                    $this->checkoutProcess->getCheckoutSession()->getCheckoutURL()
                );
            }
        }

        $this->context->smarty->assign([
            'checkout_process' => new RenderableProxy($this->checkoutProcess),
            'cart' => $presentedCart,
        ]);
        $this->setTemplate('checkout/checkout');
    }
}
