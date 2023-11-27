<?php

/**
 * Plugin Name: Implementacion QR Mercadopago Cineteatro
 * Plugin URI: https://cristiantait.com
 * Description: Cobrar con QR de Mercado Pago Dinamico
 * Author: Cristian Tait
 * Author URI: https://cristiantait.com
 * Version: 0.0.3
 *
 *
 * 
 *
 */

add_filter("woocommerce_payment_gateways", "add_gateway_class");
add_action("wp_ajax_revisar_pagoqr", "revisar_pagoqr", 1);
add_action("wp_ajax_nopriv_revisar_pagoqr", "revisar_pagoqr", 1);
add_action("plugins_loaded", "init_gateway_class");

function add_gateway_class($gateways)
{
    $gateways[] = "WC_MPQr_Gateway";
    return $gateways;
}

function revisar_pagoqr()
{
    if ($_POST["dataid"]) {
        $order_id = $_POST["dataid"];
        $qr_data = get_post_meta($order_id, "qr_status", true);
        if ($qr_data == "approved") {
            $order = wc_get_order($order_id);
            $urlok = $order->get_checkout_order_received_url();
            $final_url = str_replace("order-pay", "order-received", $urlok);
            echo "<h3 class='pagorecibido' style='text-align: left;'><img style='max-width:45px;float:left;margin-right: 15px;'  src='' . esc_url(plugins_url('img/5aa78e207603fc558cffbf19.png', __FILE__)) . ' '>  Felicitaciones hemos recibido su pago! </h3></br><a class='button' href='' . $final_url . '>Continuar al pedido </a>";
            die;
        }
    }
    die;
}

function init_gateway_class()
{
    class WC_MPQr_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            define("MPQR_DIR_PATH", plugin_dir_path(__FILE__));
            define("MPQR_DIR_URL", plugin_dir_url(__FILE__));
            $this->id = "qrmp_gateway";
            /*$this->icon = apply_filters("woocommerce_icon", plugins_url("qr-code-mp/img/logos-tarjetas.png", plugin_dir_path(__FILE__));*/
            $this->has_fields = false;
            $this->method_title = "Mercado Pago QR";
            $this->method_description = "El Sistema Mercado Pago QR permite cobrar con cualquier APP que permita pagos con QR.";
            $this->supports = array("products");
            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->get_option("title");
            $this->description = $this->get_option("description");
            $this->enabled = $this->get_option("enabled");
            $this->client_id = $this->get_option("client_id");
            $this->client_secret_id = $this->get_option("client_secret_id");
            $this->user_id = $this->get_option("user_id");
            $this->pos_id = $this->get_option("pos_id");
            add_action("woocommerce_update_options_payment_gateways_" . $this->id, array($this, "process_admin_options"));
            add_action("woocommerce_api_mpqr", array($this, "webhook"));
            add_action("woocommerce_thankyou_" . $this->id, array($this, "thankyou_page"));
            add_action("woocommerce_receipt_" . $this->id, array($this, "receipt_page"));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                "enabled" => array(
                    "title" => "Enable/Disable",
                    "label" => "Enable Mercado Pago QR",
                    "type" => "checkbox",
                    "description" => '',
                    "default" => "no"
                ),
                "title" => array(
                    "title" => "Title",
                    "type" => "text",
                    "description" => "This controls the title which the user sees during checkout.",
                    "default" => "Pagar con QR",
                    "desc_tip" => true
                ),
                "description" => array(
                    "title" => "Description",
                    "type" => "textarea",
                    "description" => "This controls the description which the user sees during checkout.",
                    "default" => "Pay with your credit card via our super-cool payment gateway."
                ),
                "client_id" => array(
                    "title" => "MP Public Key",
                    "type" => "text"
                ),
                "client_secret_id" => array(
                    "title" => "MP Access Token",
                    "type" => "text"
                ),
                "user_id" => array(
                    "title" => "MP User ID",
                    "type" => "text"
                ),
                "pos_id" => array(
                    "title" => "MP POS External ID",
                    "type" => "text"
                )
            );
        }

        public function payment_fields()
        {
            if ($this->description) {
                echo wpautop(wp_kses_post($this->description));
            }
        }

        public function generate_qr_form($order_id)
        {
            global $woocommerce;
            /*$notification_url = "https://cristiantait.com/";*/
            $notification_url = get_site_url();
            $notification_url = $notification_url . "/?wc-api=mpqr";
            $order = wc_get_order($order_id);
            $order->reduce_order_stock();
            WC()->cart->empty_cart();
            $nombre = '';
            $items = $order->get_items();
            $itemsmp = array();
            foreach ($items as $item) {
                if ($item["product_id"] > 0) {
                    $product = wc_get_product($item["product_id"]);
                    if (empty($nombre)) {
                        $nombre = $product->get_name();
                    } else {
                        $nombre = $nombre . " - " . $product->get_name();
                    }
                    $itemsmp = array(
                        "title" => $product->get_name(),
                        "description" => $product->get_name(),
                        "unit_price" => intval($product->get_price()),
                        "quantity" => 1,
                        "unit_measure" => "unit",
                        "total_amount" => intval($order->get_total())
                    );
                }
            }
            $pload = [
                "external_reference" => "QR-" . $order_id,
                "title" => $nombre,
                "description" => $nombre,
                "notification_url" => $notification_url,
                "expiration_date" => "2025-08-22T16:34:56.559-04:00",
                "total_amount" => intval($order->get_total()),
                "items" => array($itemsmp)
            ];
            $body = wp_json_encode($pload);
            $options = [
                "body" => $body,
                "headers" => [
                    "Authorization" => "Bearer " . $this->client_secret_id,
                    "Content-Type" => "application/json"
                ],
                "timeout" => 30,
                "redirection" => 5,
                "blocking" => true,
                "httpversion" => "1.0",
                "sslverify" => false,
                "data_format" => "body"
            ];
            $qr_response = wp_remote_post("https://api.mercadopago.com/instore/orders/qr/seller/collectors/" . $this->user_id . "/pos/" . $this->pos_id . "/qrs", $options);
            $response_data = json_decode($qr_response['body']);
            if ($response_data) {
                // Imprimir en pantalla
                $qr_data = $response_data->qr_data;
                /*
                echo '<pre>';
                print_r( $qr_data);
                echo '</pre>';
                echo '<pre>';
                print_r($this->user_id);
                echo '</pre>';
                echo '<pre>';
                print_r($this->pos_id);
                echo '</pre>';
                echo '<pre>';
                print_r($qr_response);
                echo '</pre>';
                */
                // Imprimir en la consola
                error_log(print_r($qr_response, true));

                if ($qr_data) {
                    echo "<div id='qr'>";
                    echo "<img style='margin: 0px auto;' id='qrmpplugin' data-id='" . $order_id . "' src='https://chart.googleapis.com/chart?chs=500x500&cht=qr&chl=" .  $qr_data. "&choe=UTF-8' title='QR CODE' />";
                    echo "</div> <div id='waitingqr' style='display:none;'>";
                    echo "</br><small>Esperando notificación de pago por parte de Mercado Pago..</small>";
                    echo "</div>";
                    echo "<div class='pago-recibido'> </div>";
                    echo "<script type='text/javascript'>
                    jQuery('#waitingqr').delay(10000).fadeIn(2000);
                
                    setInterval(function () {
                        if (jQuery('.pagorecibido')[0]) {
                            // Si se encuentra el elemento con la clase 'pagorecibido', no hacer nada.
                            console.log('Elemento .pagorecibido encontrado, no se realizará la consulta.');
                        } else {
                            var ajaxurl = '" . admin_url("admin-ajax.php") . "';
                            var dataid = jQuery('#qrmpplugin').data('id');
                            console.log('Realizando consulta AJAX...');
                            jQuery.ajax({
                                type: 'POST',
                                cache: false,
                                url: ajaxurl,
                                data: {
                                    action: 'revisar_pagoqr',
                                    dataid: dataid
                                },
                                success: function (data, textStatus, XMLHttpRequest) {
                                    if (data.indexOf('received') >= 0) {
                                        console.log('Consulta exitosa. Se recibió el pago.');
                                        jQuery('#qr').fadeOut();
                                        jQuery('#waitingqr').fadeOut();
                                        jQuery('.pago-recibido').html('');
                                        jQuery('.pago-recibido').append(data);
                                    } else {
                                        console.log('Consulta exitosa. No se recibió el pago.');
                                    }
                                },
                                error: function (MLHttpRequest, textStatus, errorThrown) {
                                    console.error('Error en la consulta AJAX:', errorThrown);
                                }
                            });
                        }
                    }, 10000);
                </script>
                ";
                } else {
                    echo "<h4>QR ERROR</h4>";
                }
            }
        }

        public function receipt_page($order)
        {
            echo "<p>" . __("Gracias por tu pedido, por favor escanear el QR con tu aplicación de pagos preferida.", $this->id) . "</p>";
            echo $this->generate_qr_form($order);
        }

        public function process_payment($order_id)
        {
            $order = new WC_Order($order_id);
            return array("result" => "success", "redirect" => $order->get_checkout_payment_url(true));
        }

        public function thankyou_page($order_id)
        {
        }

        public function webhook()
        {
            header("Content-type: application/json", "HTTP/1.1 200 OK");
            $postBodyRaw = file_get_contents("php://input");
            if ($postBodyRaw) {
                $qr_reponse = json_decode($postBodyRaw);
                if ($qr_reponse->action && $qr_reponse->live_mode) {
                    if ($qr_reponse->data->id) {
                        $curl = curl_init();
                        curl_setopt_array($curl, array(
                            CURLOPT_URL => "https://api.mercadopago.com/v1/payments/" . $qr_reponse->data->id,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING => '',
                            CURLOPT_MAXREDIRS => 10,
                            CURLOPT_TIMEOUT => 0,
                            CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST => "GET",
                            CURLOPT_HTTPHEADER => array("Authorization: Bearer Token_MP")
                        ));
                        $response = curl_exec($curl);
                        curl_close($curl);
                        $paymentdata = json_decode($response);
                        $order_id = trim($paymentdata->external_reference, "QR-");
                        update_post_meta($order_id, "qr_respose", $response);
                        if ($paymentdata->status == "approved") {
                            update_post_meta($order_id, "qr_status", $paymentdata->status);
                            $order = wc_get_order($order_id);
                            $order->add_order_note("Mercado Pago QR: " . __("Payment approved.", "mp-qr"));
                            $order = wc_get_order($order_id);
                            $order->payment_complete();
                        }
                    }
                }
            }
        }
    }
}
