<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DRPM_Identity')) {
    /**
     * Builds normalized, hashed customer identifiers so the same person is recognised
     * across orders even when they type their details slightly differently
     * (e.g. "+30 690-000 0000" vs "6900000000", "John.Doe+shop@gmail.com" vs "johndoe@gmail.com").
     *
     * Only hashes are stored, never the raw personal data.
     */
    class DRPM_Identity
    {
        /**
         * Identifier keys, mapped to the settings checkbox that enables each of them.
         */
        const FIELDS = array(
            'email'            => 'customer_data_fields_email',
            'phone'            => 'customer_data_fields_email_mobile',
            'shipping_address' => 'customer_data_fields_email_shipping_address',
            'billing_address'  => 'customer_data_fields_email_billing_address',
            'full_name'        => 'customer_data_fields_email_full_name',
        );

        /**
         * Builds the identifier hashes for an order.
         *
         * @param WC_Order $order
         * @return array<string,string|null> Hash per identifier key.
         */
        public static function fromOrder($order)
        {
            $phone = $order->get_billing_phone();
            if ('' === trim((string) $phone) && method_exists($order, 'get_shipping_phone')) {
                $phone = $order->get_shipping_phone();
            }

            $billing = array(
                'address_1' => $order->get_billing_address_1(),
                'address_2' => $order->get_billing_address_2(),
                'city'      => $order->get_billing_city(),
                'postcode'  => $order->get_billing_postcode(),
                'country'   => $order->get_billing_country(),
            );
            $shipping = array(
                'address_1' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
                'city'      => $order->get_shipping_city(),
                'postcode'  => $order->get_shipping_postcode(),
                'country'   => $order->get_shipping_country(),
            );

            return self::build(
                $order->get_billing_email(),
                $phone,
                $shipping,
                $billing,
                $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
            );
        }

        /**
         * Builds the identifier hashes for the customer currently checking out.
         *
         * @param WC_Customer $customer
         * @return array<string,string|null>
         */
        public static function fromCustomer($customer)
        {
            $phone = $customer->get_billing_phone();
            if ('' === trim((string) $phone) && method_exists($customer, 'get_shipping_phone')) {
                $phone = $customer->get_shipping_phone();
            }

            $billing = array(
                'address_1' => $customer->get_billing_address_1(),
                'address_2' => $customer->get_billing_address_2(),
                'city'      => $customer->get_billing_city(),
                'postcode'  => $customer->get_billing_postcode(),
                'country'   => $customer->get_billing_country(),
            );
            $shipping = array(
                'address_1' => $customer->get_shipping_address_1(),
                'address_2' => $customer->get_shipping_address_2(),
                'city'      => $customer->get_shipping_city(),
                'postcode'  => $customer->get_shipping_postcode(),
                'country'   => $customer->get_shipping_country(),
            );

            return self::build(
                $customer->get_billing_email(),
                $phone,
                $shipping,
                $billing,
                $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name()
            );
        }

        /**
         * @return array<string,string|null>
         */
        private static function build($email, $phone, array $shipping, array $billing, $fullName)
        {
            // An order without a separate shipping address ships to the billing address.
            if ('' === self::normalizeAddress($shipping)) {
                $shipping = $billing;
            }

            return array(
                'email'            => self::hash(self::normalizeEmail($email)),
                'phone'            => self::hash(self::normalizePhone($phone)),
                'shipping_address' => self::hash(self::normalizeAddress($shipping)),
                'billing_address'  => self::hash(self::normalizeAddress($billing)),
                'full_name'        => self::hash(self::normalizeName($fullName)),
            );
        }

        /**
         * Lowercases, and for Gmail removes dots and "+tag" aliases.
         */
        public static function normalizeEmail($email)
        {
            $email = strtolower(trim((string) $email));
            if ('' === $email || false === strpos($email, '@')) {
                return '';
            }

            list($local, $domain) = explode('@', $email, 2);
            if (in_array($domain, array('gmail.com', 'googlemail.com'), true)) {
                $local = str_replace('.', '', $local);
                $domain = 'gmail.com';
            }
            $plus = strpos($local, '+');
            if (false !== $plus) {
                $local = substr($local, 0, $plus);
            }

            return $local . '@' . $domain;
        }

        /**
         * Keeps digits only and compares the last 10 digits, so country codes and
         * formatting don't matter.
         */
        public static function normalizePhone($phone)
        {
            $digits = preg_replace('/\D+/', '', (string) $phone);
            if (strlen($digits) < 6) {
                return '';
            }

            return substr($digits, -10);
        }

        /**
         * Lowercases, removes accents, punctuation and whitespace.
         */
        public static function normalizeAddress(array $address)
        {
            $street = self::normalizeText(($address['address_1'] ?? '') . ' ' . ($address['address_2'] ?? ''));
            if ('' === $street) {
                return '';
            }

            return implode('|', array(
                $street,
                self::normalizeText($address['postcode'] ?? ''),
                self::normalizeText($address['city'] ?? ''),
                strtolower((string) ($address['country'] ?? '')),
            ));
        }

        /**
         * Lowercases and removes accents; word order is ignored so
         * "Doe John" and "John Doe" match.
         */
        public static function normalizeName($name)
        {
            $name = remove_accents(trim((string) $name));
            $name = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
            $parts = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
            if (!$parts || count($parts) < 2) {
                return '';
            }
            sort($parts);

            return implode(' ', $parts);
        }

        private static function normalizeText($text)
        {
            $text = remove_accents((string) $text);
            $text = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);

            return preg_replace('/[^\p{L}\p{N}]+/u', '', $text);
        }

        private static function hash($value)
        {
            return '' === $value ? null : md5($value);
        }
    }
}
