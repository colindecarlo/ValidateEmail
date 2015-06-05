<?php

namespace ServiceTo;

class ValidateEmail
{
    /**
     * The address to supply in our MAIL FROM connection to the SMTP servers we're talking to.
     *
     * @var string
     */
    private $testaddress = "validateemail@service.to";

    /**
     * Alias to lookup
     *
     * @param  string  $emailaddress  email address to test
     * @return boolean
     */
    public function test($emailaddress)
    {
        return $this->lookup($emailaddress);
    }

    /**
     * Lookup all MX records and check each until we have a success
     *
     * @param  string  $emailaddress  email address to look up
     * @return boolean
     */
    public function lookup($emailaddress)
    {
        list($user, $domain) = preg_split("/@/", trim($emailaddress));

        if ($user == "") {
            throw new ValidateEmailException("Blank user name");
        }

        if ($domain == "") {
            throw new ValidateEmailException("Blank domain name");
        }

        $mxhosts = array();
        $weight = array();
        if (! getmxrr($domain, $mxhosts, $weight)) {
            throw new ValidateEmailException("No MX records");
        }

        // pick first one and check it.
        array_multisort($weight, $mxhosts);
        return !! first($mxhosts, function ($mxhost) use ($emailaddress) {
            return call_user_func_array(array($this, 'verify'), array($emailaddress, $mxhost));
        });
    }

    /**
     * Connect to the mail server on port 25 and see if it allows mail for the users' supplied email address.
     *
     * @param  string  $emailaddress  email address to test
     * @param  string  $mxhost        mail server host name to connect to and test
     * @return boolean
     */
    private function verify($emailaddress, $mxhost)
    {
        if (! ($socket = @stream_socket_client("tcp://" . $mxhost . ":25", $errno, $errstr, 3))) {
            return false;
        }

        $onFailure = function () use ($socket) {
            call_user_func(array($this, sendQuitAndClose), $socket);
        };

        // server will say hi...
        $response = $this->listen($socket);
        list($code, $message) = $this->parseResponse($response);

        if (when($code != 220, $onFailure)) {
            return false;
        }

        // say hello.
        list($code, $message) = $this->sendEHLO($socket);

        if (when($code != 250, $onFailure)) {
            return false;
        }

        $response = $this->send(sprintf("MAIL FROM:<%s>\r\n", $this->testaddress));
        list($code, $message) = $this->parseResponse($response);

        if (when($code != 250, $onFailure)) {
            return false;
        }
        // give them the user's address.
        $response = $this->send(sprintf("RCPT TO:<%s>\r\n", $emailaddress));
        list($code, $message) = $this->parseResponse($response);

        if (when($code != 250, $onFailure)) {
            return false;
        }

        $this->sendQuitAndClose($socket);
        return true;
    }

    private function sendQuitAndClose($socket)
    {
        $this->send("QUIT\r\n");
        socket_close($socket);
    }

    private function sendEHLO($socket)
    {
        $message = "EHLO ValidateEmail\r\n";
        fwrite($socket, $message);

        $code = '';
        while(($buf = fgets($socket)) && $code != "250") {
            $buffer .= $buf;
            list($code, $message) = $this->parseResponse($buffer);
        }
        $response = trim($buffer, "\r\n");

        $lines = explode("\n", $response);
        return $this->parseResponse(end($lines));
    }

    private function listen($socket)
    {
        return trim(fgets($socket, 2048), "\r\n");
    }

    private function send($socket, $message)
    {
        fwrite($socket, $message);
        return $this->listen($socket);
    }

    private function parseResponse($response)
    {
        return preg_split('/\s+/', $response, 2);
    }
}
