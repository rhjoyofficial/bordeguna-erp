<?php

namespace App\Modules\Crm\Messaging;

/**
 * The Bangladeshi SMS gateways, already wired.
 *
 * Joy, 30 August 2026: he has not settled on a provider and does not want that
 * choice to be a code change. So a preset is nothing but a bag of the same
 * variables the Custom option exposes - pick one, paste the key, press Test.
 * Switching provider later is picking a different row on the same screen.
 *
 * ⚠⚠ A PRESET IS A STARTING POINT, NOT A GUARANTEE. Every field it fills stays
 * editable, because a gateway can change its API without telling anybody and the
 * fix must not need a developer. `confidence` says how much of each one was read
 * off the vendor's own published documentation:
 *   - `documented`: request AND response shape both come from the vendor docs.
 *   - `partial`: the request is documented, the success rule is a best reading.
 *     These default to accepting any HTTP 2xx, which is DELIBERATELY LOOSE -
 *     ⚠ it will call a rejection a success. Press Test, read the raw reply the
 *     screen prints back, then set the exact rule. Do not send a real campaign
 *     through a `partial` provider until that is done.
 *
 * ⚠ Nothing here is a recommendation of a vendor. Prices, delivery rates and
 * masking approval are Joy's to compare; this only removes the integration work.
 */
final class ProviderPresets
{
    /**
     * @return list<array<string,mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'bulksmsbd',
                'label' => 'BulkSMSBD (bulksmsbd.net)',
                'confidence' => 'documented',
                'note' => 'Response codes are published: 202 is accepted, 1007 is out of balance, '
                    .'1012 means a Bangla message needs an approved masking sender ID.',
                'credential_fields' => ['api_key'],
                'config' => [
                    'endpoint' => 'https://bulksmsbd.net/api/smsapi',
                    'method' => 'post_form',
                    'auth' => 'params',
                    'credential_map' => ['api_key' => 'api_key'],
                    'recipient_field' => 'number',
                    'message_field' => 'message',
                    'sender_field' => 'senderid',
                    'number_format' => 'msisdn88',
                    'extra_params' => [],
                    'unicode_params' => [],
                    'success' => ['mode' => 'json_in', 'path' => 'response_code', 'values' => ['202']],
                    'error_path' => 'error_message',
                    'message_id_path' => null,
                    'balance_endpoint' => 'https://bulksmsbd.net/api/getBalanceApi',
                    'balance_path' => 'balance',
                ],
            ],
            [
                'key' => 'alpha_sms',
                'label' => 'Alpha SMS / sms.net.bd',
                'confidence' => 'documented',
                'note' => 'Replies {"error":0,...} on acceptance and carries a request_id, so a '
                    .'delivery report can be fetched later. Sender ID is optional.',
                'credential_fields' => ['api_key'],
                'config' => [
                    'endpoint' => 'https://api.sms.net.bd/sendsms',
                    'method' => 'post_form',
                    'auth' => 'params',
                    'credential_map' => ['api_key' => 'api_key'],
                    'recipient_field' => 'to',
                    'message_field' => 'msg',
                    'sender_field' => 'sender_id',
                    'number_format' => 'msisdn88',
                    'extra_params' => [],
                    'unicode_params' => [],
                    'success' => ['mode' => 'json_in', 'path' => 'error', 'values' => ['0']],
                    'error_path' => 'msg',
                    'message_id_path' => 'data.request_id',
                    'balance_endpoint' => 'https://api.sms.net.bd/user/balance/',
                    'balance_path' => 'data.balance',
                ],
            ],
            [
                'key' => 'elitbuzz',
                'label' => 'Elitbuzz (bangladeshsms.com)',
                'confidence' => 'partial',
                'note' => '⚠ Bangla needs type=unicode, which is already wired to switch itself on '
                    .'when the message leaves the GSM alphabet. The success rule is NOT confirmed - '
                    .'press Test and set it from the raw reply.',
                'credential_fields' => ['api_key'],
                'config' => [
                    'endpoint' => 'https://bangladeshsms.com/smsapi',
                    'method' => 'get',
                    'auth' => 'params',
                    'credential_map' => ['api_key' => 'api_key'],
                    'recipient_field' => 'contacts',
                    'message_field' => 'msg',
                    'sender_field' => 'senderid',
                    'number_format' => 'msisdn88',
                    'extra_params' => ['type' => 'text'],
                    'unicode_params' => ['type' => 'unicode'],
                    'success' => ['mode' => 'http_ok', 'path' => null, 'values' => []],
                    'error_path' => null,
                    'message_id_path' => null,
                    'balance_endpoint' => null,
                    'balance_path' => null,
                ],
            ],
            [
                'key' => 'mimsms',
                'label' => 'MiMSMS (api.mimsms.com)',
                'confidence' => 'partial',
                'note' => '⚠⚠ MiMSMS requires the SERVER IP TO BE WHITELISTED on their panel before '
                    .'any call is accepted - the VPS address, not your laptop. The username is the '
                    .'panel login email. ⚠ Confirm the message field name against apidoc.mimsms.com; '
                    .'the reply carries statusCode "200" and a trxnId on success.',
                'credential_fields' => ['UserName', 'Apikey'],
                'config' => [
                    'endpoint' => 'https://api.mimsms.com/api/SmsSending/OneToMany',
                    'method' => 'post_json',
                    'auth' => 'params',
                    'credential_map' => ['UserName' => 'UserName', 'Apikey' => 'Apikey'],
                    'recipient_field' => 'MobileNumber',
                    'message_field' => 'TransactionSms',
                    'sender_field' => 'SenderName',
                    'number_format' => 'msisdn88',
                    'extra_params' => ['TransactionType' => 'T'],
                    'unicode_params' => [],
                    'success' => ['mode' => 'json_in', 'path' => 'statusCode', 'values' => ['200']],
                    'error_path' => 'responseResult',
                    'message_id_path' => 'trxnId',
                    'balance_endpoint' => null,
                    'balance_path' => null,
                ],
            ],
            [
                'key' => 'custom',
                'label' => 'Something else - I will fill in the fields',
                'confidence' => 'partial',
                'note' => 'Every gateway on this list is only these fields filled in. Read the '
                    .'vendor API page, copy the endpoint and the parameter names across, press Test, '
                    .'then set the success rule from the raw reply.',
                'credential_fields' => ['api_key'],
                'config' => [
                    'endpoint' => '',
                    'method' => 'post_form',
                    'auth' => 'params',
                    'credential_map' => ['api_key' => 'api_key'],
                    'recipient_field' => 'to',
                    'message_field' => 'message',
                    'sender_field' => null,
                    'number_format' => 'msisdn88',
                    'extra_params' => [],
                    'unicode_params' => [],
                    'success' => ['mode' => 'http_ok', 'path' => null, 'values' => []],
                    'error_path' => null,
                    'message_id_path' => null,
                    'balance_endpoint' => null,
                    'balance_path' => null,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $preset) {
            if ($preset['key'] === $key) {
                return $preset;
            }
        }

        return null;
    }
}
