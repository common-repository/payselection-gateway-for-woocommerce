<?php

namespace Payselection;

defined( 'ABSPATH' ) || exit;

class Order extends \WC_Order
{
    use Traits\Options;
    
    /**
     * getRequestData Create order data for Payselection
     *
     * @return void
     */
    public function getRequestData()
    {
        // Get plugin options
        $options = self::get_options();

        $successUrl = $this->get_checkout_order_received_url();
        $cancelUrl = esc_url(wc_get_checkout_url());

        // Redirect links
        $extraData = [
            "WebhookUrl"    => home_url('/wc-api/wc_payselection_gateway_webhook'),
            "SuccessUrl"    => $successUrl,
            "CancelUrl"     => $cancelUrl,
            "DeclineUrl"    => $cancelUrl,
            "FailUrl"       => $cancelUrl,
        ];

        $data = [
            "MetaData" => [
                "PaymentType" => !empty($options->type) ? $options->type : "Pay",
            ],
            "PaymentRequest" => [
                "OrderId" => implode("-",[$this->get_id(), $options->site_id, time()]),
                "Amount" => number_format($this->get_total(), 2, ".", ""),
                "Currency" => $this->get_currency(),
                "Description" => esc_html__('Order payment #', 'payselection-gateway-for-woocommerce') . $this->get_id(),
                "PaymentMethod" => "Card",
                "RebillFlag" => !empty($options->rebill) ? !!$options->rebill : false,
                "ExtraData" => $extraData,
            ],
            "CustomerInfo" => [
                "Language" => !empty($options->language) ? $options->language : "en",
            ],
        ];

        if (!empty($billing_email = $this->get_billing_email())) {
            $data['CustomerInfo']['Email'] = $billing_email;
            $data['CustomerInfo']['ReceiptEmail'] = $billing_email;
        }

        if (!empty($billing_phone = $this->get_billing_phone())) {
            $billing_phone = str_replace(array('(', ')', ' ', '-'), '', $billing_phone);
            $format_billing_phone = '+' === substr($billing_phone, 0, 1) ? $billing_phone : '+' . $billing_phone;
            $data['CustomerInfo']['Phone'] = $format_billing_phone;
        }

        if (!empty($billing_address = $this->get_billing_address_1())) {
            $data['CustomerInfo']['Address'] = $billing_address;
        }

        if (!empty($billing_city = $this->get_billing_city())) {
            $data['CustomerInfo']['Town'] = $billing_city;
        }

        if (!empty($billing_zip = $this->get_billing_postcode())) {
            $data['CustomerInfo']['ZIP'] = $billing_zip;
        }

        if ($options->receipt === 'yes') {
            $data['ReceiptData'] = $this->getReceiptData($options);
        }

        return $data;
    }
    
    /**
     * getReceiptData Create receipt data
     *
     * @param  mixed $options
     * @return void
     */
    public function getReceiptData(object $options)
    {
        $items = [];
        $cart = $this->get_items();

        foreach ($cart as $item_data) {
            $product = $item_data->get_product();
            $items[] = [
                'name'           => mb_substr($product->get_name(), 0, 120),
                'sum'            => (float) number_format(floatval($item_data->get_total()), 2, '.', ''),
                'price'          => (float) number_format($product->get_price(), 2, '.', ''),
                'quantity'       => (int) $item_data->get_quantity(),
                'payment_method' => 'full_prepayment',
                'payment_object' => 'commodity',
                'vat'            => [
                    'type'          => $options->company_vat,
                ] 
            ];
        }
        
        if ($this->get_total_shipping()) {
			$items[] = [
                'name'           => esc_html__('Shipping', 'payselection-gateway-for-woocommerce'),
                'sum'            => (float) number_format($this->get_total_shipping(), 2, '.', ''),
                'price'          => (float) number_format($this->get_total_shipping(), 2, '.', ''),
                'quantity'       => 1,
                'payment_method' => 'full_prepayment',
                'payment_object' => 'commodity',
                'vat'            => [
                    'type'          => $options->company_vat,
                ]  
            ];
        }

        return [
            'timestamp' => date('d.m.Y H:i:s'),
            'external_id' => (string) $this->get_id(),
            'receipt' => [
                'client' => [
                    'email' => $this->get_billing_email(),
                ],
                'company' => [
                    'email' => $options->company_email,
                    'inn' => $options->company_inn,
                    'sno' => $options->company_tax_system,
                    'payment_address' => $options->company_address,
                ],
                'items' => $items,
                'payments' => [
                    [
                        'type' => 1,
                        'sum' => (float) number_format($this->get_total(), 2, '.', ''),
                    ]
                ],
                'total' => (float) number_format($this->get_total(), 2, '.', ''),
            ],
        ];
    }
    
    /**
     * getChargeCancelData Create data for Charge or Cancel
     *
     * @return void
     */
    public function getChargeCancelData()
    {
        return [
            "TransactionId" => $this->get_meta('BlockTransactionId'),
            "Amount"        => number_format($this->get_total(), 2, ".", ""),
            "Currency"      => $this->get_currency(),
            "WebhookUrl"    => home_url('/wc-api/wc_payselection_gateway_webhook'),
        ];
    }

    /**
     * getRefundData Create data for Refund
     *
     * @return void
     */
    public function getPayselectionRefundData($amount)
    {
        // Get plugin options
        $options = self::get_options();

        $items = [];

        $data = [
            "TransactionId" => $this->get_meta('TransactionId', true),
            "Amount"        => number_format($amount, 2, ".", ""),
            "Currency"      => $this->get_currency(),
            "WebhookUrl"    => home_url('/wc-api/wc_payselection_gateway_webhook'),
        ];

        $items[] = [
            'name'           => esc_html__('Refund', 'payselection-gateway-for-woocommerce'),
            'sum'            => (float) number_format(floatval($amount), 2, '.', ''),
            'price'          => (float) number_format($amount, 2, '.', ''),
            'quantity'       => 1,
            'payment_method' => 'full_prepayment',
            'payment_object' => 'commodity',
            'vat'            => [
                'type'          => (string) $options->company_vat,
            ] 
        ];

        if ($options->receipt === 'yes') {
            $data['ReceiptData'] = [
                'timestamp' => date('d.m.Y H:i:s'),
                'external_id' => (string) $this->get_id(),
                'receipt' => [
                    'client' => [
                        'email' => $this->get_billing_email(),
                    ],
                    'company' => [
                        'email' => (string) $options->company_email,
                        'inn' => (string) $options->company_inn,
                        'sno' => (string) $options->company_tax_system,
                        'payment_address' => (string) $options->company_address,
                    ],
                    'items' => $items,
                    'payments' => [
                        [
                            'type' => 1,
                            'sum' => (float) number_format($amount, 2, '.', ''),
                        ]
                    ],
                    'total' => (float) number_format($amount, 2, '.', ''),
                ],
            ];
        }

        return $data;
    }
    
}
