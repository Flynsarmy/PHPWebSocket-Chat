<?php

/*
	Based on PHP WebSocket Server 0.2
	 - http://code.google.com/p/php-websocket-server/
	 - http://code.google.com/p/php-websocket-server/wiki/Scripting

	WebSocket Protocol 07
	 - http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-07
	 - Supported by Firefox 6 (30/08/2011)

	Whilst a big effort is made to follow the protocol documentation, the current script version may unknowingly differ.
	Please report any bugs you may find, all feedback and questions are welcome!
*/


class PHPWebSocket
{
	// maximum amount of clients that can be connected at one time
	const WS_MAX_CLIENTS = 100;

	// maximum amount of clients that can be connected at one time on the same IP v4 address
	const WS_MAX_CLIENTS_PER_IP = 15;

	// amount of seconds a client has to send data to the server, before a ping request is sent to the client,
	// if the client has not completed the opening handshake, the ping request is skipped and the client connection is closed
	const WS_TIMEOUT_RECV = 10;

	// amount of seconds a client has to reply to a ping request, before the client connection is closed
	const WS_TIMEOUT_PONG = 5;

	// the maximum length, in bytes, of a frame's payload data (a message consists of 1 or more frames), this is also internally limited to 2,147,479,538
	const WS_MAX_FRAME_PAYLOAD_RECV = 100000;

	// the maximum length, in bytes, of a message's payload data, this is also internally limited to 2,147,483,647
	const WS_MAX_MESSAGE_PAYLOAD_RECV = 500000;




	// internal
	const WS_FIN =  128;
	const WS_MASK = 128;

	const WS_OPCODE_CONTINUATION = 0;
	const WS_OPCODE_TEXT =         1;
	const WS_OPCODE_BINARY =       2;
	const WS_OPCODE_CLOSE =        8;
	const WS_OPCODE_PING =         9;
	const WS_OPCODE_PONG =         10;

	const WS_PAYLOAD_LENGTH_16 = 126;
	const WS_PAYLOAD_LENGTH_63 = 127;

	const WS_READY_STATE_CONNECTING = 0;
	const WS_READY_STATE_OPEN =       1;
	const WS_READY_STATE_CLOSING =    2;
	const WS_READY_STATE_CLOSED =     3;

	const WS_STATUS_NORMAL_CLOSE =             1000;
	const WS_STATUS_GONE_AWAY =                1001;
	const WS_STATUS_PROTOCOL_ERROR =           1002;
	const WS_STATUS_UNSUPPORTED_MESSAGE_TYPE = 1003;
	const WS_STATUS_MESSAGE_TOO_BIG =          1004;

	const WS_STATUS_TIMEOUT = 3000;

	// global vars
	public $wsClients       = array();
	public $wsRead          = array();
	public $wsClientCount   = 0;
	public $wsClientIPCount = array();
	public $wsOnEvents      = array();

	/*
		$this->wsClients[ integer ClientID ] = array(
			0 => resource  Socket,                            // client socket
			1 => string    MessageBuffer,                     // a blank string when there's no incoming frames
			2 => integer   ReadyState,                        // between 0 and 3
			3 => integer   LastRecvTime,                      // set to time() when the client is added
			4 => int/false PingSentTime,                      // false when the server is not waiting for a pong
			5 => int/false CloseStatus,                       // close status that wsOnClose() will be called with
			6 => integer   IPv4,                              // client's IP stored as a signed long, retrieved from ip2long()
			7 => int/false FramePayloadDataLength,            // length of a frame's payload data, reset to false when all frame data has been read (cannot reset to 0, to allow reading of mask key)
			8 => integer   FrameBytesRead,                    // amount of bytes read for a frame, reset to 0 when all frame data has been read
			9 => string    FrameBuffer,                       // joined onto end as a frame's data comes in, reset to blank string when all frame data has been read
			10 => integer  MessageOpcode,                     // stored by the first frame for fragmented messages, default value is 0
			11 => integer  MessageBufferLength                // the payload data length of MessageBuffer
		)

		$wsRead[ integer ClientID ] = resource Socket         // this one-dimensional array is used for socket_select()
															  // $wsRead[ 0 ] is the socket listening for incoming client connections

		$wsClientCount = integer ClientCount                  // amount of clients currently connected

		$wsClientIPCount[ integer IP ] = integer ClientCount  // amount of clients connected per IP v4 address
	*/

	// server state functions
	function wsStartServer($host, $port) {
		if (isset($this->wsRead[0])) return false;

		if (!$this->wsRead[0] = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) {
			return false;
		}
		if (!socket_set_option($this->wsRead[0], SOL_SOCKET, SO_REUSEADDR, 1)) {
			socket_close($this->wsRead[0]);
			return false;
		}
		if (!socket_bind($this->wsRead[0], $host, $port)) {
			socket_close($this->wsRead[0]);
			return false;
		}
		if (!socket_listen($this->wsRead[0], 10)) {
			socket_close($this->wsRead[0]);
			return false;
		}

		$write = array();
		$except = array();

		$nextPingCheck = time() + 1;
		while (isset($this->wsRead[0])) {
			$changed = $this->wsRead;
			$result = socket_select($changed, $write, $except, 1);

			if ($result === false) {
				socket_close($this->wsRead[0]);
				return false;
			}
			elseif ($result > 0) {
				foreach ($changed as $clientID => $socket) {
					if ($clientID != 0) {
						// client socket changed
						$buffer = '';
						$bytes = @socket_recv($socket, $buffer, 4096, 0);

						if ($bytes === false) {
							// error on recv, remove client socket (will check to send close frame)
							$this->wsSendClientClose($clientID, self::WS_STATUS_PROTOCOL_ERROR);
						}
						elseif ($bytes > 0) {
							// process handshake or frame(s)
							if (!$this->wsProcessClient($clientID, $buffer, $bytes)) {
								$this->wsSendClientClose($clientID, self::WS_STATUS_PROTOCOL_ERROR);
							}
						}
						else {
							// 0 bytes received from client, meaning the client closed the TCP connection
							$this->wsRemoveClient($clientID);
						}
					}
					else {
						// listen socket changed
						$client = socket_accept($this->wsRead[0]);
						if ($client !== false) {
							// fetch client IP as integer
							$clientIP = '';
							$result = socket_getpeername($client, $clientIP);
							$clientIP = ip2long($clientIP);

							if ($result !== false && $this->wsClientCount < self::WS_MAX_CLIENTS && (!isset($this->wsClientIPCount[$clientIP]) || $this->wsClientIPCount[$clientIP] < self::WS_MAX_CLIENTS_PER_IP)) {
								$this->wsAddClient($client, $clientIP);
							}
							else {
								socket_close($client);
							}
						}
					}
				}
			}

			if (time() >= $nextPingCheck) {
				$this->wsCheckIdleClients();
				$nextPingCheck = time() + 1;
			}
		}

		return true; // returned when wsStopServer() is called
	}
	function wsStopServer() {
		// check if server is not running
		if (!isset($this->wsRead[0])) return false;

		// close all client connections
		foreach ($this->wsClients as $clientID => $client) {
			// if the client's opening handshake is complete, tell the client the server is 'going away'
			if ($client[2] != self::WS_READY_STATE_CONNECTING) {
				$this->wsSendClientClose($clientID, self::WS_STATUS_GONE_AWAY);
			}
			socket_close($client[0]);
		}

		// close the socket which listens for incoming clients
		socket_close($this->wsRead[0]);

		// reset variables
		$this->wsRead          = array();
		$this->wsClients       = array();
		$this->wsClientCount   = 0;
		$this->wsClientIPCount = array();

		return true;
	}

	// client timeout functions
	function wsCheckIdleClients() {
		$time = time();
		foreach ($this->wsClients as $clientID => $client) {
			if ($client[2] != self::WS_READY_STATE_CLOSED) {
				// client ready state is not closed
				if ($client[4] !== false) {
					// ping request has already been sent to client, pending a pong reply
					if ($time >= $client[4] + self::WS_TIMEOUT_PONG) {
						// client didn't respond to the server's ping request in self::WS_TIMEOUT_PONG seconds
						$this->wsSendClientClose($clientID, self::WS_STATUS_TIMEOUT);
						$this->wsRemoveClient($clientID);
					}
				}
				elseif ($time >= $client[3] + self::WS_TIMEOUT_RECV) {
					// last data was received >= self::WS_TIMEOUT_RECV seconds ago
					if ($client[2] != self::WS_READY_STATE_CONNECTING) {
						// client ready state is open or closing
						$this->wsClients[$clientID][4] = time();
						$this->wsSendClientMessage($clientID, self::WS_OPCODE_PING, '');
					}
					else {
						// client ready state is connecting
						$this->wsRemoveClient($clientID);
					}
				}
			}
		}
	}

	// client existence functions
	function wsAddClient($socket, $clientIP) {
		// increase amount of clients connected
		$this->wsClientCount++;

		// increase amount of clients connected on this client's IP
		if (isset($this->wsClientIPCount[$clientIP])) {
			$this->wsClientIPCount[$clientIP]++;
		}
		else {
			$this->wsClientIPCount[$clientIP] = 1;
		}

		// fetch next client ID
		$clientID = $this->wsGetNextClientID();

		// store initial client data
		$this->wsClients[$clientID] = array($socket, '', self::WS_READY_STATE_CONNECTING, time(), false, 0, $clientIP, false, 0, '', 0, 0);

		// store socket - used for socket_select()
		$this->wsRead[$clientID] = $socket;
	}
	function wsRemoveClient($clientID) {
		// fetch close status (which could be false), and call wsOnClose
		$closeStatus = $this->wsClients[$clientID][5];
		if ( array_key_exists('close', $this->wsOnEvents) )
			foreach ( $this->wsOnEvents['close'] as $func )
				$func($clientID, $closeStatus);

		// close socket
		$socket = $this->wsClients[$clientID][0];
		socket_close($socket);

		// decrease amount of clients connected on this client's IP
		$clientIP = $this->wsClients[$clientID][6];
		if ($this->wsClientIPCount[$clientIP] > 1) {
			$this->wsClientIPCount[$clientIP]--;
		}
		else {
			unset($this->wsClientIPCount[$clientIP]);
		}

		// decrease amount of clients connected
		$this->wsClientCount--;

		// remove socket and client data from arrays
		unset($this->wsRead[$clientID], $this->wsClients[$clientID]);
	}

	// client data functions
	function wsGetNextClientID() {
		$i = 1; // starts at 1 because 0 is the listen socket
		while (isset($this->wsRead[$i])) $i++;
		return $i;
	}
	function wsGetClientSocket($clientID) {
		return $this->wsClients[$clientID][0];
	}

	// client read functions
	function wsProcessClient($clientID, &$buffer, $bufferLength) {
		if ($this->wsClients[$clientID][2] == self::WS_READY_STATE_OPEN) {
			// handshake completed
			$result = $this->wsBuildClientFrame($clientID, $buffer, $bufferLength);
		}
		elseif ($this->wsClients[$clientID][2] == self::WS_READY_STATE_CONNECTING) {
			// handshake not completed
			$result = $this->wsProcessClientHandshake($clientID, $buffer);
			if ($result) {
				$this->wsClients[$clientID][2] = self::WS_READY_STATE_OPEN;

				if ( array_key_exists('open', $this->wsOnEvents) )
					foreach ( $this->wsOnEvents['open'] as $func )
						$func($clientID);
			}
		}
		else {
			// ready state is set to closed
			$result = false;
		}

		return $result;
	}
	function wsBuildClientFrame($clientID, &$buffer, $bufferLength) {
		// increase number of bytes read for the frame, and join buffer onto end of the frame buffer
		$this->wsClients[$clientID][8] += $bufferLength;
		$this->wsClients[$clientID][9] .= $buffer;

		// check if the length of the frame's payload data has been fetched, if not then attempt to fetch it from the frame buffer
		if ($this->wsClients[$clientID][7] !== false || $this->wsCheckSizeClientFrame($clientID) == true) {
			// work out the header length of the frame
			$headerLength = ($this->wsClients[$clientID][7] <= 125 ? 0 : ($this->wsClients[$clientID][7] <= 65535 ? 2 : 8)) + 6;

			// check if all bytes have been received for the frame
			$frameLength = $this->wsClients[$clientID][7] + $headerLength;
			if ($this->wsClients[$clientID][8] >= $frameLength) {
				// check if too many bytes have been read for the frame (they are part of the next frame)
				$nextFrameBytesLength = $this->wsClients[$clientID][8] - $frameLength;
				if ($nextFrameBytesLength > 0) {
					$this->wsClients[$clientID][8] -= $nextFrameBytesLength;
					$nextFrameBytes = substr($this->wsClients[$clientID][9], $frameLength);
					$this->wsClients[$clientID][9] = substr($this->wsClients[$clientID][9], 0, $frameLength);
				}

				// process the frame
				$result = $this->wsProcessClientFrame($clientID);

				// check if the client wasn't removed, then reset frame data
				if (isset($this->wsClients[$clientID])) {
					$this->wsClients[$clientID][7] = false;
					$this->wsClients[$clientID][8] = 0;
					$this->wsClients[$clientID][9] = '';
				}

				// if there's no extra bytes for the next frame, or processing the frame failed, return the result of processing the frame
				if ($nextFrameBytesLength <= 0 || !$result) return $result;

				// build the next frame with the extra bytes
				return $this->wsBuildClientFrame($clientID, $nextFrameBytes, $nextFrameBytesLength);
			}
		}

		return true;
	}
	function wsCheckSizeClientFrame($clientID) {
		// check if at least 2 bytes have been stored in the frame buffer
		if ($this->wsClients[$clientID][8] > 1) {
			// fetch payload length in byte 2, max will be 127
			$payloadLength = ord(substr($this->wsClients[$clientID][9], 1, 1)) & 127;

			if ($payloadLength <= 125) {
				// actual payload length is <= 125
				$this->wsClients[$clientID][7] = $payloadLength;
			}
			elseif ($payloadLength == 126) {
				// actual payload length is <= 65,535
				if (substr($this->wsClients[$clientID][9], 3, 1) !== false) {
					// at least another 2 bytes are set
					$payloadLengthExtended = substr($this->wsClients[$clientID][9], 2, 2);
					$array = unpack('na', $payloadLengthExtended);
					$this->wsClients[$clientID][7] = $array['a'];
				}
			}
			else {
				// actual payload length is > 65,535
				if (substr($this->wsClients[$clientID][9], 9, 1) !== false) {
					// at least another 8 bytes are set
					$payloadLengthExtended = substr($this->wsClients[$clientID][9], 2, 8);

					// check if the frame's payload data length exceeds 2,147,483,647 (31 bits)
					// the maximum integer in PHP is "usually" this number. More info: http://php.net/manual/en/language.types.integer.php
					$payloadLengthExtended32_1 = substr($payloadLengthExtended, 0, 4);
					$array = unpack('Na', $payloadLengthExtended32_1);
					if ($array['a'] != 0 || ord(substr($payloadLengthExtended, 4, 1)) & 128) {
						$this->wsSendClientClose($clientID, self::WS_STATUS_MESSAGE_TOO_BIG);
						return false;
					}

					// fetch length as 32 bit unsigned integer, not as 64 bit
					$payloadLengthExtended32_2 = substr($payloadLengthExtended, 4, 4);
					$array = unpack('Na', $payloadLengthExtended32_2);

					// check if the payload data length exceeds 2,147,479,538 (2,147,483,647 - 14 - 4095)
					// 14 for header size, 4095 for last recv() next frame bytes
					if ($array['a'] > 2147479538) {
						$this->wsSendClientClose($clientID, self::WS_STATUS_MESSAGE_TOO_BIG);
						return false;
					}

					// store frame payload data length
					$this->wsClients[$clientID][7] = $array['a'];
				}
			}

			// check if the frame's payload data length has now been stored
			if ($this->wsClients[$clientID][7] !== false) {

				// check if the frame's payload data length exceeds self::WS_MAX_FRAME_PAYLOAD_RECV
				if ($this->wsClients[$clientID][7] > self::WS_MAX_FRAME_PAYLOAD_RECV) {
					$this->wsClients[$clientID][7] = false;
					$this->wsSendClientClose($clientID, self::WS_STATUS_MESSAGE_TOO_BIG);
					return false;
				}

				// check if the message's payload data length exceeds 2,147,483,647 or self::WS_MAX_MESSAGE_PAYLOAD_RECV
				// doesn't apply for control frames, where the payload data is not internally stored
				$controlFrame = (ord(substr($this->wsClients[$clientID][9], 0, 1)) & 8) == 8;
				if (!$controlFrame) {
					$newMessagePayloadLength = $this->wsClients[$clientID][11] + $this->wsClients[$clientID][7];
					if ($newMessagePayloadLength > self::WS_MAX_MESSAGE_PAYLOAD_RECV || $newMessagePayloadLength > 2147483647) {
						$this->wsSendClientClose($clientID, self::WS_STATUS_MESSAGE_TOO_BIG);
						return false;
					}
				}

				return true;
			}
		}

		return false;
	}
	function wsProcessClientFrame($clientID) {
		// store the time that data was last received from the client
		$this->wsClients[$clientID][3] = time();

		// fetch frame buffer
		$buffer = &$this->wsClients[$clientID][9];

		// check at least 6 bytes are set (first 2 bytes and 4 bytes for the mask key)
		if (substr($buffer, 5, 1) === false) return false;

		// fetch first 2 bytes of header
		$octet0 = ord(substr($buffer, 0, 1));
		$octet1 = ord(substr($buffer, 1, 1));

		$fin = $octet0 & self::WS_FIN;
		$opcode = $octet0 & 15;

		$mask = $octet1 & self::WS_MASK;
		if (!$mask) return false; // close socket, as no mask bit was sent from the client

		// fetch byte position where the mask key starts
		$seek = $this->wsClients[$clientID][7] <= 125 ? 2 : ($this->wsClients[$clientID][7] <= 65535 ? 4 : 10);

		// read mask key
		$maskKey = substr($buffer, $seek, 4);

		$array = unpack('Na', $maskKey);
		$maskKey = $array['a'];
		$maskKey = array(
			$maskKey >> 24,
			($maskKey >> 16) & 255,
			($maskKey >> 8) & 255,
			$maskKey & 255
		);
		$seek += 4;

		// decode payload data
		if (substr($buffer, $seek, 1) !== false) {
			$data = str_split(substr($buffer, $seek));
			foreach ($data as $key => $byte) {
				$data[$key] = chr(ord($byte) ^ ($maskKey[$key % 4]));
			}
			$data = implode('', $data);
		}
		else {
			$data = '';
		}

		// check if this is not a continuation frame and if there is already data in the message buffer
		if ($opcode != self::WS_OPCODE_CONTINUATION && $this->wsClients[$clientID][11] > 0) {
			// clear the message buffer
			$this->wsClients[$clientID][11] = 0;
			$this->wsClients[$clientID][1] = '';
		}

		// check if the frame is marked as the final frame in the message
		if ($fin == self::WS_FIN) {
			// check if this is the first frame in the message
			if ($opcode != self::WS_OPCODE_CONTINUATION) {
				// process the message
				return $this->wsProcessClientMessage($clientID, $opcode, $data, $this->wsClients[$clientID][7]);
			}
			else {
				// increase message payload data length
				$this->wsClients[$clientID][11] += $this->wsClients[$clientID][7];

				// push frame payload data onto message buffer
				$this->wsClients[$clientID][1] .= $data;

				// process the message
				$result = $this->wsProcessClientMessage($clientID, $this->wsClients[$clientID][10], $this->wsClients[$clientID][1], $this->wsClients[$clientID][11]);

				// check if the client wasn't removed, then reset message buffer and message opcode
				if (isset($this->wsClients[$clientID])) {
					$this->wsClients[$clientID][1] = '';
					$this->wsClients[$clientID][10] = 0;
					$this->wsClients[$clientID][11] = 0;
				}

				return $result;
			}
		}
		else {
			// check if the frame is a control frame, control frames cannot be fragmented
			if ($opcode & 8) return false;

			// increase message payload data length
			$this->wsClients[$clientID][11] += $this->wsClients[$clientID][7];

			// push frame payload data onto message buffer
			$this->wsClients[$clientID][1] .= $data;

			// if this is the first frame in the message, store the opcode
			if ($opcode != self::WS_OPCODE_CONTINUATION) {
				$this->wsClients[$clientID][10] = $opcode;
			}
		}

		return true;
	}
	function wsProcessClientMessage($clientID, $opcode, &$data, $dataLength) {
		// check opcodes
		if ($opcode == self::WS_OPCODE_PING) {
			// received ping message
			return $this->wsSendClientMessage($clientID, self::WS_OPCODE_PONG, $data);
		}
		elseif ($opcode == self::WS_OPCODE_PONG) {
			// received pong message (it's valid if the server did not send a ping request for this pong message)
			if ($this->wsClients[$clientID][4] !== false) {
				$this->wsClients[$clientID][4] = false;
			}
		}
		elseif ($opcode == self::WS_OPCODE_CLOSE) {
			// received close message
			if (substr($data, 1, 1) !== false) {
				$array = unpack('na', substr($data, 0, 2));
				$status = $array['a'];
			}
			else {
				$status = false;
			}

			if ($this->wsClients[$clientID][2] == self::WS_READY_STATE_CLOSING) {
				// the server already sent a close frame to the client, this is the client's close frame reply
				// (no need to send another close frame to the client)
				$this->wsClients[$clientID][2] = self::WS_READY_STATE_CLOSED;
			}
			else {
				// the server has not already sent a close frame to the client, send one now
				$this->wsSendClientClose($clientID, self::WS_STATUS_NORMAL_CLOSE);
			}

			$this->wsRemoveClient($clientID);
		}
		elseif ($opcode == self::WS_OPCODE_TEXT || $opcode == self::WS_OPCODE_BINARY) {
			if ( array_key_exists('message', $this->wsOnEvents) )
				foreach ( $this->wsOnEvents['message'] as $func )
					$func($clientID, $data, $dataLength, $opcode == self::WS_OPCODE_BINARY);
		}
		else {
			// unknown opcode
			return false;
		}

		return true;
	}
	function wsProcessClientHandshake($clientID, &$buffer) {
		// fetch headers and request line
		$sep = strpos($buffer, "\r\n\r\n");
		if (!$sep) return false;

		$headers = explode("\r\n", substr($buffer, 0, $sep));
		$headersCount = sizeof($headers); // includes request line
		if ($headersCount < 1) return false;

		// fetch request and check it has at least 3 parts (space tokens)
		$request = &$headers[0];
		$requestParts = explode(' ', $request);
		$requestPartsSize = sizeof($requestParts);
		if ($requestPartsSize < 3) return false;

		// check request method is GET
		if (strtoupper($requestParts[0]) != 'GET') return false;

		// check request HTTP version is at least 1.1
		$httpPart = &$requestParts[$requestPartsSize - 1];
		$httpParts = explode('/', $httpPart);
		if (!isset($httpParts[1]) || (float) $httpParts[1] < 1.1) return false;

		// store headers into a keyed array: array[headerKey] = headerValue
		$headersKeyed = array();
		for ($i=1; $i<$headersCount; $i++) {
			$parts = explode(':', $headers[$i]);
			if (!isset($parts[1])) return false;

			$headersKeyed[trim($parts[0])] = trim($parts[1]);
		}

		// check Host header was received
		if (!isset($headersKeyed['Host'])) return false;

		// check Sec-WebSocket-Key header was received and decoded value length is 16
		if (!isset($headersKeyed['Sec-WebSocket-Key'])) return false;
		$key = $headersKeyed['Sec-WebSocket-Key'];
		if (strlen(base64_decode($key)) != 16) return false;

		// check Sec-WebSocket-Version header was received and value is 7
		if (!isset($headersKeyed['Sec-WebSocket-Version']) || (int) $headersKeyed['Sec-WebSocket-Version'] < 7) return false; // should really be != 7, but Firefox 7 beta users send 8

		// work out hash to use in Sec-WebSocket-Accept reply header
		$hash = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

		// build headers
		$headers = array(
			'HTTP/1.1 101 Switching Protocols',
			'Upgrade: websocket',
			'Connection: Upgrade',
			'Sec-WebSocket-Accept: '.$hash
		);
		$headers = implode("\r\n", $headers)."\r\n\r\n";

		// send headers back to client
		$socket = $this->wsClients[$clientID][0];

		$left = strlen($headers);
		do {
			$sent = @socket_send($socket, $headers, $left, 0);
			if ($sent === false) return false;

			$left -= $sent;
			if ($sent > 0) $headers = substr($headers, $sent);
		}
		while ($left > 0);

		return true;
	}

	// client write functions
	function wsSendClientMessage($clientID, $opcode, $message) {
		// check if client ready state is already closing or closed
		if ($this->wsClients[$clientID][2] == self::WS_READY_STATE_CLOSING || $this->wsClients[$clientID][2] == self::WS_READY_STATE_CLOSED) return true;

		// fetch message length
		$messageLength = strlen($message);

		// set max payload length per frame
		$bufferSize = 4096;

		// work out amount of frames to send, based on $bufferSize
		$frameCount = ceil($messageLength / $bufferSize);
		if ($frameCount == 0) $frameCount = 1;

		// set last frame variables
		$maxFrame = $frameCount - 1;
		$lastFrameBufferLength = ($messageLength % $bufferSize) != 0 ? ($messageLength % $bufferSize) : ($messageLength != 0 ? $bufferSize : 0);

		// loop around all frames to send
		for ($i=0; $i<$frameCount; $i++) {
			// fetch fin, opcode and buffer length for frame
			$fin = $i != $maxFrame ? 0 : self::WS_FIN;
			$opcode = $i != 0 ? self::WS_OPCODE_CONTINUATION : $opcode;

			$bufferLength = $i != $maxFrame ? $bufferSize : $lastFrameBufferLength;

			// set payload length variables for frame
			if ($bufferLength <= 125) {
				$payloadLength = $bufferLength;
				$payloadLengthExtended = '';
				$payloadLengthExtendedLength = 0;
			}
			elseif ($bufferLength <= 65535) {
				$payloadLength = self::WS_PAYLOAD_LENGTH_16;
				$payloadLengthExtended = pack('n', $bufferLength);
				$payloadLengthExtendedLength = 2;
			}
			else {
				$payloadLength = self::WS_PAYLOAD_LENGTH_63;
				$payloadLengthExtended = pack('xxxxN', $bufferLength); // pack 32 bit int, should really be 64 bit int
				$payloadLengthExtendedLength = 8;
			}

			// set frame bytes
			$buffer = pack('n', (($fin | $opcode) << 8) | $payloadLength) . $payloadLengthExtended . substr($message, $i*$bufferSize, $bufferLength);

			// send frame
			$socket = $this->wsClients[$clientID][0];

			$left = 2 + $payloadLengthExtendedLength + $bufferLength;
			do {
				$sent = @socket_send($socket, $buffer, $left, 0);
				if ($sent === false) return false;

				$left -= $sent;
				if ($sent > 0) $buffer = substr($buffer, $sent);
			}
			while ($left > 0);
		}

		return true;
	}
	function wsSendClientClose($clientID, $status=false) {
		// check if client ready state is already closing or closed
		if ($this->wsClients[$clientID][2] == self::WS_READY_STATE_CLOSING || $this->wsClients[$clientID][2] == self::WS_READY_STATE_CLOSED) return true;

		// store close status
		$this->wsClients[$clientID][5] = $status;

		// send close frame to client
		$status = $status !== false ? pack('n', $status) : '';
		$this->wsSendClientMessage($clientID, self::WS_OPCODE_CLOSE, $status);

		// set client ready state to closing
		$this->wsClients[$clientID][2] = self::WS_READY_STATE_CLOSING;
	}

	// client non-internal functions
	function wsClose($clientID) {
		return $this->wsSendClientClose($clientID, self::WS_STATUS_NORMAL_CLOSE);
	}
	function wsSend($clientID, $message, $binary=false) {
		return $this->wsSendClientMessage($clientID, $binary ? self::WS_OPCODE_BINARY : self::WS_OPCODE_TEXT, $message);
	}

	function log( $message )
	{
		echo date('Y-m-d H:i:s: ') . $message . "\n";
	}

	function bind( $type, $func )
	{
		if ( !isset($this->wsOnEvents[$type]) )
			$this->wsOnEvents[$type] = array();
		$this->wsOnEvents[$type][] = $func;
	}

	function unbind( $type='' )
	{
		if ( $type ) unset($this->wsOnEvents[$type]);
		else $this->wsOnEvents = array();
	}
}
?>