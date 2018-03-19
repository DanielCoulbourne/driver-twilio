<?php

namespace BotMan\Drivers\Twilio;

use Twilio\Twiml;
use Twilio\Rest\Client as Twilio;
use Illuminate\Support\Facades\Cache;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Outgoing\Question;
use Symfony\Component\HttpFoundation\Response;
use BotMan\BotMan\Messages\Attachments\Location;
use BotMan\BotMan\Interfaces\DriverEventInterface;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use Illuminate\Support\Facades\Log;

class TwilioMessageDriver extends TwilioDriver
{
    const DRIVER_NAME = 'TwilioMessage';

    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     */
    public function matchesRequest()
    {
        return $this->event->has('MessageSid') && $this->isSignatureValid();
    }

    /**
     * @param  IncomingMessage $message
     * @return \BotMan\BotMan\Messages\Incoming\Answer
     */
    public function getConversationAnswer(IncomingMessage $message)
    {
        return Answer::create($message->getText())
            ->setValue($message->getText())
            ->setInteractiveReply(true)
            ->setMessage($message);
    }

    /**
     * Retrieve the chat message.
     *
     * @return array
     */
    public function getMessages()
    {
        if (empty($this->messages)) {
            $message = new IncomingMessage($this->event->get('Body'), $this->event->get('From'), $this->event->get('To'));

            $this->messages = [$message];
        }

        return $this->messages;
    }

    /**
     * @return bool|DriverEventInterface
     */
    public function hasMatchingEvent()
    {
        return false;
    }

    /**
     * @param string|Question|IncomingMessage $message
     * @param IncomingMessage $matchingMessage
     * @param array $additionalParameters
     * @return Response
     */
    public function buildServicePayload($message, $matchingMessage, $additionalParameters = [])
    {
        $parameters = $additionalParameters;
        $text = '';

        $parameters['buttons'] = [];

        if ($message instanceof Question) {
            $text = $message->getText();
            $parameters['buttons'] = $message->getButtons() ?? [];
        } elseif ($message instanceof Twiml) {
            $parameters['twiml'] = $message;
        } elseif ($message instanceof OutgoingMessage) {
            $attachment = $message->getAttachment();
            $text = $message->getText();
            if ($attachment instanceof Location === false && ! is_null($attachment)) {
                $parameters['media'] = $attachment->getUrl();
            }
        } else {
            $text = $message;
        }

        $parameters['text'] = $text;
        $parameters['recipient'] = $matchingMessage->getSender();

        return $parameters;
    }

    /**
     * @param mixed $payload
     * @return Response
     */
    public function sendPayload($payload)
    {
        if (!$this->requestUri) {
            Log::debug('sending SMS. aborting Twiml');
            $response = $this->sendSms($payload);
            return Response::create('Why Do I Have To Do This')->send();
        }
        
        if (isset($payload['twiml'])) {
            return Response::create((string) $payload['twiml'])->send();
        }

        $response = new Twiml();
        $message = $response->message();

        $body = $payload['text'];

        foreach ((array) $payload['buttons'] as $button) {
            $body .= "\n".$button['text'];
        }
        $message->body($body);
        if (isset($payload['media'])) {
            $message->media($payload['media']);
        }

        $finalResponse = Response::create((string) $response);
        $sentResponse = $finalResponse->send();

        return $sentResponse;
    }

    public function sendSms($payload)
    {
        $sid = config('botman.twilio.sid');
        $token = config('botman.twilio.token');
        $client = new Twilio($sid, $token);

        Cache::store('redis')->put('message', 'sent', 10);

        return $client->messages->create(
            $payload['recipient'],
            array(
                "from" => config('botman.twilio.fromNumber'),
                "body" => $payload['text'],
            )
        );
    }
}
