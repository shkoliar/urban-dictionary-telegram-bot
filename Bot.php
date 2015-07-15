<?php
 /**
 * @author: Dmitry Shkoliar @DrmitryNek
 * @created: 12.07.15 12:50
 * @project: urban-dictionary-telegram-bot
 * @file: Bot.php
 */

class Bot {

    /**
     * Telegram Bot API Url
     */
    const TELEGRAM_API_URL = "https://api.telegram.org/bot";

    /**
     * Urban Dictionary API Url
     */
    const URBAN_DICTIONARY_API_URL = "http://api.urbandictionary.com/v0/";

    /**
     * Not found phrase.
     */
    const NOT_FOUND_PHRASE = "\xf0\x9f\x98\xa2 I'm sad coz I can't find definition for your query. Try to rephrase or look for something else.";

    /**
     * Bot API Url with token to perform requests
     * @var string
     */
    protected $botApiUrl;

    /**
     * Received message object
     * @var \stdClass
     */
    protected $message;

    /**
     * Curl handle to use for requests
     * @var resource
     */
    protected $curl;

    /**
     * Creates a new Bot instance
     * @param string $token The token string to auth bot requests
     */
    public function __construct($token)
    {
        $this->botApiUrl = Bot::TELEGRAM_API_URL . $token . "/";
        $this->curl = curl_init();
    }

    /**
     * Sends a request to the bot api method with optional arguments
     * @param string $method Name of the bot api method
     * @param array $arguments Optional arguments
     * @throws \Exception
     * @return \stdClass response object
     */
    protected function telegramRequest($method, array $arguments = null)
    {
        $curl_options = [
            CURLOPT_URL => $this->botApiUrl . $method,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POST => 0,
            CURLOPT_POSTFIELDS => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0
        ];

        if (!is_null($arguments)) {
            $curl_options[CURLOPT_POST] = 1;
            $curl_options[CURLOPT_POSTFIELDS] = $arguments;
        }

        curl_setopt_array($this->curl, $curl_options);

        $result = curl_exec($this->curl);

        if (!$result) {
            throw new Exception(curl_error($this->curl), curl_errno($this->curl));
        } else {
            $response = json_decode($result);

            if (!$response->ok) {
                throw new Exception($response->description, $response->error_code);
            }

            return $response;
        }
    }

    /**
     * Sends a request to UrbanDictionary api
     * @param string $query query for api request
     * @throws \Exception
     * @return \stdClass the api response object
     */
    protected function urbanDictionaryRequest($query)
    {
        $result = file_get_contents(Bot::URBAN_DICTIONARY_API_URL . $query);
        $response = json_decode($result);
        return $response;
    }

    /**
     * Sends typing action to user or group chat
     * @return stdClass
     */
    protected function sendChatTypingAction()
    {
        return $this->telegramRequest("sendChatAction", [
            "chat_id" => $this->message->chat->id,
            "action" => "typing"
        ]);
    }

    /**
     * Replies to the message with the given text
     * @param string $text The reply text
     * @param array $options Optional options
     * @return stdClass
     */
    protected function sendMessage($text, array $options = [])
    {
        $this->sendChatTypingAction();

        return $this->telegramRequest("sendMessage", array_merge($options, [
            "disable_web_page_preview" => true,
            "reply_to_message_id" => $this->message->message_id,
            "chat_id" => $this->message->chat->id,
            "text" => $text
        ]));
    }

    protected function getUrbanDictionaryDefinition($query, $definition)
    {
        $json = $this->urbanDictionaryRequest("define?term=" . urlencode($query));

        if (isset($json->list[$definition - 1])) {
            $found = $json->list[$definition - 1];

            $definitionText = "Phrase: " . $found->word;

            if (count($json->list) > 1) {
                $definitionText .= " (found " . count($json->list) . " definitions)";
            }

            $definitionText .= "\n\n" . $found->definition;

            if (!empty($found->example)) {
                $definitionText .= "\n\nExample:\n" . $found->example;
            }

            if (count($json->list) > 1) {
                $definitionText .= "\n\nFound " . count($json->list) . " definitions.";
            }

            return $definitionText;
        }

        return Bot::NOT_FOUND_PHRASE;
    }

    protected function getUrbanDictionaryRandomDefinition()
    {
        $json = $this->urbanDictionaryRequest("random");

        if (isset($json->list[0])) {
            $found = $json->list[0];

            $definitionText = "Phrase: " . $found->word;

            if (count($json->list) > 1) {
                $definitionText .= " (found " . count($json->list) . " definitions)";
            }

            $definitionText .= "\n\n" . $found->definition;

            if (!empty($found->example)) {
                $definitionText .= "\n\nExample:\n" . $found->example;
            }

            return $definitionText;
        }

        return Bot::NOT_FOUND_PHRASE;
    }

    /**
     * Use this method to specify a url and receive incoming updates via an outgoing webhook.
     * Whenever there is an update for the bot, we will send an HTTPS POST request to the specified url,
     * containing a JSON-serialized Update. In case of an unsuccessful request,
     * we will give up after a reasonable amount of attempts.
     * @param string $url
     * @return stdClass
     */
    public function setWebhook($url)
    {
        return $this->telegramRequest("setWebhook", [
            "url" => $url
        ]);
    }

    /**
     * Central function to handle incoming Updates via WebHoook
     * Performs event handling and hook calling
     */
    public function respond()
    {
        $data = file_get_contents("php://input");
        $json = json_decode($data);

        if (isset($json->message)) {
            $this->message = $json->message;
        }

        list($command, $query) = array_map("trim", explode(" ", $this->message->text, 2));

        $command = strtolower($command);

        if ($query) {
            $definitionMarkerPos = strrpos($query, "*");
            if ($definitionMarkerPos !== false) {
                $definition = intval(trim(substr($query, $definitionMarkerPos + 1)));
                if ($definition) {
                    $query = trim(substr($query, 0, $definitionMarkerPos));
                }
            }

            if (!$definition) {
                $definition = 1;
            }
        }

        $message = "";

        if ($command == "/help" || $command == "/help@urbanbot") {
            $message = "Send /define command with your query to get definition from Urban Dictionary. " .
                        "Also you can append *number of definition which you wish to get.\n\n" .
                        "Example: \"/define handsome\" or \"/define handsome *2\" to get second definition.";
        } else if (($command == "/define" || $command == "/define@urbanbot") && $query) {
            $message = $this->getUrbanDictionaryDefinition($query, $definition);
        } else if ($command == "/define" || $command == "/start" || $command == "/define@urbanbot" || $command == "/start@urbanbot") {
            $message = "Hello, " . $this->message->from->first_name . "!\n\nI'm Urban Dictionary Bot. Send me /help command to see what I can do. \xF0\x9F\x98\x89";
        } else if ($command == "/random" || $command == "/random@urbanbot") {
            $message = $this->getUrbanDictionaryRandomDefinition();
        }

        if (strlen($message) > 4096) {
            $messages = str_split($message, 4090);
            foreach ($messages as $index => $message) {
                if ($index == 0) {
                    $this->sendMessage($message . '...');
                } elseif ($index == count($messages) - 1) {
                    $this->sendMessage('...' . $message);
                } else {
                    $this->sendMessage('...' . $message . '...');
                }
            }
        } else {
            $message && $this->sendMessage($message);
        }
    }
}