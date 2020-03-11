<?php

namespace PaycoPayment\Payment;

use Epayco\Epayco;
use Epayco\Exceptions\ErrorException;
use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Mvc\Controller;
use XF\Purchasable\Purchase;
use XF\Payment\AbstractProvider;
use XF\Payment\CallbackState;


class Payco extends AbstractProvider
{
    public function getTitle()
    {
        return 'ePayco';
    }

    public function verifyConfig(array &$options, &$errors = [])
    {
        if (empty($options['publicKey']) OR empty($options['privateKey']))
        {
            $errors[] = \XF::phrase('epayco_you_need_to_provide_your_public_and_private_key');
            return false;
        }

        $error = '';
        $this->_setupPayco($options, $error);
        if ($error)
        {
            $errors[] = $error;
            return false;
        }

        return true;
    }

    public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        $paymentProfile = $purchase->paymentProfile;
        $this->_setupPayco($paymentProfile->options);

        $bankList = [];
        try
        {
            $banksListData = $this->epayco->bank->pseBank();

            if ($banksListData->success != 1)
            {
                throw new \Exception('error');
            }

            foreach($banksListData->data AS $bank)
            {
                $bankList[] = (array)$bank;
            }
        } catch (\Exception $e) {
            $bankList = [];
        }

        $viewParams = $this->getPaymentParams($purchaseRequest, $purchase);
        $viewParams['bankList'] = $bankList;

        return $controller->view('PaycoPayment:Payment\Initiate', 'payment_initiate_payco', $viewParams);
    }

    public function processPayment(Controller $controller, PurchaseRequest $purchaseRequest, PaymentProfile $paymentProfile, Purchase $purchase)
    {
        $this->_setupPayco($paymentProfile->options);

        $currency = $purchase->currency;

        $cost = $purchase->cost;


        if ($controller->filter('pse', 'bool') === true)
        {
            $bankDetails = $controller->filter([
                'bank_id' => 'uint',
                'id_type' => 'str',
                'id_number' => 'str',
                'first_name' => 'str',
                'last_name' => 'str',
                'cell_phone' => 'uint'
            ]);

            try
            {
                $pse = $this->epayco->bank->create(array(
                    "bank" => $bankDetails['bank_id'],
                    "invoice" => $purchaseRequest->request_key,
                    "description" => $purchase->title,
                    "value" => $cost,
                    "tax" => "0",
                    "tax_base" => "0",
                    "currency" => $currency,
                    "type_person" => ($bankDetails['id_type'] == 'NIT' ? 1 : 0),
                    "doc_type" => $bankDetails['id_type'],
                    "doc_number" => $bankDetails['id_number'],
                    "name" => $bankDetails['first_name'],
                    "last_name" => $bankDetails['last_name'],
                    "email" => $purchase->purchaser->email,
                    "country" => "CO",
                    "cell_phone" => $bankDetails['cell_phone'],
                    "url_response" => $purchase->returnUrl,
                    "url_confirmation" => $this->getCallbackUrl(),
                    "method_confirmation" => "GET",
                ));

                if ($pse->success != 1)
                {
                    throw new \Exception(\XF::phrase('something_went_wrong_please_try_again'));
                }
            } catch (\Exception $e) {
                throw $controller->exception($controller->error($e->getMessage()));
            }

            return $controller->redirect($pse->data->urlbanco);
        }

        $cardHolder = $controller->filter([
            'epaycoToken' => 'str',
            'first_name' => 'str',
            'last_name' => 'str',
            'id_type' => 'str',
            'id_number' => 'uint',
        ]);

        if (empty($cardHolder['epaycoToken'])) {
            $error = \XF::phrase('error_occurred_while_creating_epayco_card_token');
            throw $controller->exception($controller->error($error));
        }

        $transactionId = '';

        $plan = [];
        $charge = [];

        $cardToken = $cardHolder['epaycoToken'];

        $customer = $this->epayco->customer->create([
            'token_card' => $cardToken,
            'email' => $purchase->purchaser->email,
        ]);

        if (!property_exists($customer, 'success') || $customer->success !== true) {
            throw $controller->exception($controller->error(\XF::phrase('error_ocurred_while_creating_epayco_customer')));
        }
        $customerId = $customer->data->customerId;

        $charge = $this->epayco->charge->create(array(
            "token_card" => $cardToken,
            "customer_id" => $customerId,
            "doc_type" => $cardHolder['id_type'],
            "doc_number" => (string)$cardHolder['id_number'],
            "name" => $cardHolder['first_name'],
            "last_name" => $cardHolder['last_name'],
            "email" => $purchase->purchaser->email,
            "bill" => $purchaseRequest->request_key,
            "description" => $purchase->title,
            "value" => $cost,
            "tax" => "0",
            "tax_base" => "0",
            "currency" => $currency,
            "dues" => "1",
            "url_response" => $purchase->returnUrl,
            "url_confirmation" => $this->getCallbackUrl(),
        ));

        if (!property_exists($charge, 'data') || !property_exists($charge->data, 'ref_payco')) {
            throw $controller->exception($controller->error(\XF::phrase('something_went_wrong_please_try_again')));
        } else if ($charge->status === false || !($charge->data->estado === 'Aceptada' || $charge->data->estado === 'Pendiente')) {
            $errorDescription = $charge->data->respuesta;
            throw $controller->exception($controller->error($errorDescription));
        }

        $transactionId = $charge->data->ref_payco;

        /** @var \XF\Repository\Payment $paymentRepo */
        $paymentRepo = \XF::repository('XF:Payment');

        $paymentRepo->logCallback(
            $purchaseRequest->request_key,
            $this->providerId,
            $transactionId,
            'info',
            'Customer and plan/charge created',
            [
                'plan' => $plan,
                'customer' => $customer,
                'charge' => $charge
            ],
            $customerId
        );

        return $controller->redirect($purchase->returnUrl);
    }

    /**
     * @param \XF\Http\Request $request
     *
     * @return CallbackState
     */
    public function setupCallback(\XF\Http\Request $request)
    {
        $state = new CallbackState();

        $input = $request->getInput();
        $state->input = $input;
        $state->signature = $request->filter('x_signature', 'str');

        $state->transactionId = $request->filter('x_transaction_id', 'str');
        $state->ref_payco = $request->filter('x_ref_payco', 'str');
        $state->requestKey = $request->filter('x_id_invoice', 'str');

        $state->cost = $request->filter('x_amount', 'str');
        $state->currency = $request->filter('x_currency_code', 'str');
        $state->ip = $request->getIp();

        return $state;
    }

    public function validateCallback(CallbackState $state)
    {
        if (!$state->signature || empty($state->signature))
        {
            $state->logType = 'error';
            $state->logMessage = 'La notificación recibida por ePayco no contenía una firma.';
            $state->httpCode = 500;

            return false;
        }

        $paymentProfile = $state->getPaymentProfile();
        $purchaseRequest = $state->getPurchaseRequest();

        $computed = hash('sha256', $paymentProfile->options['clientId'] . '^' .
            $paymentProfile->options['key'] . '^' . $state->ref_payco . '^' . $state->transactionId . '^' .
            $state->cost . '^' . $state->currency);

        if ($computed !== $state->signature) {
            $state->logType = 'error';
            $state->logMessage = 'Información recibida desde ePayco no pudo ser verificada. La firma no concuerda.';
            $state->httpCode = 500;

            return false;
        }

        if (!$paymentProfile || !$purchaseRequest)
        {
            $state->logType = 'error';
            $state->logMessage = 'Solicitud o perfil de pago inválido.';
            return false;
        }

        try
        {
            $this->_setupPayco($paymentProfile->options);
            $retrievedTransaction = $this->epayco->charge->transaction($state->ref_payco);
        }
        catch(ErrorException $e)
        {
            $state->logType = 'error';
            $state->logMessage = 'Algo salió mal conectándose a ePayco: ' . $e->getMessage();
            return false;
        }

        if (!property_exists($retrievedTransaction, 'success') || $retrievedTransaction->success !== true)
        {
            $errorDescription = '';
            if (property_exists($retrievedTransaction, 'text_response')) {
                $errorDescription = $retrievedTransaction->text_response;
            }
            $state->logType = 'error';
            $state->logMessage = 'La transacción consultada a ePayco falló' . (!empty($errorDescription) ? ': ' . $errorDescription : '');
            return false;
        }

        $state->transaction = (array)$retrievedTransaction->data;

        return $state;
    }

    public function validateTransaction(CallbackState $state)
    {
        if (!$state->transactionId)
        {
            $state->logType = 'info';
            $state->logMessage = 'No hay un ID de transacción. No hay acción para hacer.';
            return false;
        }
        return parent::validateTransaction($state);
    }

    public function validateCost(CallbackState $state)
    {
        $purchaseRequest = $state->getPurchaseRequest();

        $currency = $purchaseRequest->cost_currency;
        $cost = $this->prepareCost($purchaseRequest->cost_amount, $currency);

        // 1	Aceptada
        // 2	Rechazada
        // 3	Pendiente
        // 4	Fallida
        // 6	Reversada
        // 7	Retenida
        // 8	Iniciada
        // 9	Exprirada
        // 10	Abandonada
        // 11	Cancelada
        // 12	Antifraude
        switch ($state->transaction['x_cod_transaction_state'])
        {
            case 1:
                $costValidated = (
                    $state->transaction['x_amount'] === $cost
                    && strtoupper($state->transaction['x_currency_code']) === $currency
                );

                if (!$costValidated)
                {
                    $state->logType = 'error';
                    $state->logMessage = 'Cantidad reportada como pagada es inválida';
                    return false;
                }
                break;
        }

        return true;
    }

    public function getPaymentResult(CallbackState $state)
    {
        switch ($state->transaction['x_cod_transaction_state'])
        {
            case '1':
                $state->paymentResult = CallbackState::PAYMENT_RECEIVED;
                break;

            case '6':
                $state->paymentResult = CallbackState::PAYMENT_REVERSED;
                break;
        }
    }

    public function prepareLogData(CallbackState $state)
    {
        $state->logDetails = $state->input;
    }

    public function verifyCurrency(PaymentProfile $paymentProfile, $currencyCode)
    {
        return (in_array($currencyCode, $this->supportedCurrencies));
    }

    protected $supportedCurrencies = [
        'COP',
        'USD'
    ];

    /**
     * Lista de monedas que deben ser tratadas como enteros
     * El resto deben ser tratados como flotantes ¯\_(ツ)_/¯
     * @var array
     */
    protected $noDecimalCurrencies = [
        'COP'
    ];

    protected function getPaymentParams(PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        $paymentProfile = $purchase->paymentProfile;

        return [
            'purchaseRequest' => $purchaseRequest,
            'paymentProfile' => $paymentProfile,
            'purchaser' => $purchase->purchaser,
            'purchase' => $purchase,
            'purchasableTypeId' => $purchase->purchasableTypeId,
            'purchasableId' => $purchase->purchasableId,
            'publicKey' => $paymentProfile->options['publicKey'],
            'cost' => $this->prepareCost($purchase->cost, $purchase->currency)
        ];
    }

    protected function prepareCost($cost, $currency)
    {
        if (in_array($currency, $this->noDecimalCurrencies))
        {
            $cost = intval($cost);
        } else {
            $cost = floatval($cost);
        }

        return $cost;
    }

    private $epayco;

    private function _setupPayco(array $options, &$error = '')
    {
        if ($this->epayco instanceof Epayco)
        {
            return;
        }

        require_once (__DIR__ . '/../vendor/autoload.php');

        try
        {
            $this->epayco = new Epayco(array(
                "apiKey" => $options['publicKey'],
                "privateKey" => $options['privateKey'],
                "lenguage" => "ES",
                "test" => (\XF::config('enableLivePayments') ? false : true)
            ));
        } catch (\Exception $e) {
            $error = 'El siguiente error ocurrió mientras inicializábamos la librería de ePayCo: ' . $e->getMessage();
        }
    }
}