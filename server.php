<?php
define("STATE_NICKNAME", 1);
define("STATE_CHAT", 2);

// So that we don't report 'casting to Integer' warnings
error_reporting(0);

/* Allow the script to hang around waiting for connections. */
set_time_limit(0);

/* Turn on implicit output flushing so we see what we're getting
 * as it comes in. */
ob_implicit_flush();

$address = 'localhost'; //host
$port = '9399'; //port

if (($sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
    echo "socket_create() failed: reason: " . socket_strerror(socket_last_error()) . "\n";
}

if (!socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1)) { 
    echo "socket_set_option() failed: reason: " . socket_strerror(socket_last_error($sock)); 
    exit; 
}

if (socket_bind($sock, $address, $port) === false) {
    echo "socket_bind() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
}

if (socket_listen($sock, 5) === false) {
    echo "socket_listen() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
}

// Create an array for all connected clients
$clients = array($sock);
// Create an array for all the rooms
$rooms = array("general" => 0, "test" => 0, "games" => 0, "kids" => 0); // TODO: allow dynamically created rooms

do {
    // Create a copy so $clients doesn't get modified by socket_select()
    $clientsChanged = $clients;
    // Get a list of all the clients that have data to be read from
    $write = NULL;
    $except = NULL;
    $numChanged = socket_select($clientsChanged, $write, $except, 0);
    if ($numChanged === false) {
        echo "socket_select() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
    }
    else if ($numChanged < 1) {
        // If none of the clients updated, go to next iteration
        continue;
    }

    // Handle new connections
    if (in_array($sock, $clientsChanged)) {
        // Accept the client, and add him to the $clients array
        $clients[] = $newsock = socket_accept($sock);
        
        // Send the client a welcome message
        socket_write($newsock, "Welcome to Brian's chat server!\n".
        "There are " . (count($clients) - 1) . " client(s) connected to the server\n".
        "Please enter a nickname.\n");
        
        // Logging to server 
        socket_getpeername($newsock, $ip);
        echo "New client connected: {$ip}\n";

        $serverData[$newsock]["ip"] = $ip;
        // $serverData[$newsock]["room"] = "general";
        // $rooms["general"]++;
        changeRoom($newsock, "general");
        $serverData[$newsock]["state"] = STATE_NICKNAME;
        $serverData[$newsock]["nickname"] = "";
        // Remove the listening socket from the clients-with-data array
        $key = array_search($sock, $clientsChanged);
        unset($clientsChanged[$key]);
    }

    // Handle messaging to all clients
    foreach ($clientsChanged as $key => $changedClientSocket) {
        // Socket_read while show errors when the client is disconnected, so silence the error messages
        $data = @socket_read($changedClientSocket, 1024, PHP_NORMAL_READ);
        
        // Check if the client is disconnected
        if ($data === false) {
            // Remove client for $clients array
            $key = array_search($changedClientSocket, $clients);
            unset($clients[$key]);
            echo "(" . $key . ") client disconnected.\n";
            // continue to the next client to read from, if any
            continue;
        }

        // Trim off the trailing/beginning white spaces
        $data = trim($data);
        // Check if there is any data after trimming off the spaces
        if (!empty($data)) {
            if($serverData[$changedClientSocket]["state"] === STATE_NICKNAME) {
                checkNickname($changedClientSocket, $data);
            }
            else {
                $nickname = $serverData[$changedClientSocket]["nickname"];
                // logging on server
                echo $nickname . ": " . $data . "\n";
                if ($data === "/help") {
                    // Display list of options
                    $helpText = "Available options:\n" .
                                "/leave: Leave a room\n" .
                                "/quit: Exit chat server\n" .
                                "/rooms: Lists the available rooms\n" .
                                "/join <room name>: Joins the specified room\n";
                    socket_write($changedClientSocket, $helpText);
                }
                else if ($data === "/rooms") { 
                    // Display the list of rooms
                    $roomListing = "Active rooms:\n";
                    foreach($rooms as $roomName => $numberOfUsers)
                        $roomListing .= $roomName . " (" . $numberOfUsers . ")\n";
                    socket_write($changedClientSocket, $roomListing);
                }
                else if (strpos($data, "/join ") !== false ) {
                    // Handle joining a room
                    $newRoom = substr($data, strlen("/join "));
                    if(array_key_exists($newRoom, $rooms)) {
                        changeRoom($changedClientSocket, $newRoom);
                        echo $nickname . " joined room:" . $newRoom . "\n";
                    }
                    else {
                        socket_write($changedClientSocket, "No such room. Please try again!\n");
                    }
                }
                else if ($data === "/leave") {
                    // Handle leaving a room
                    if($serverData[$changedClientSocket]["room"] !== "general") {
                        $prevRoom = changeRoom($changedClientSocket, "general");
                        echo $serverData[$changedClientSocket]["nickname"] . " left room:" . $prevRoom . "\n";
                        socket_write($changedClientSocket, "Left " . $prevRoom . " room. Auto-joined general chat room.\n");
                    }
                    else {
                        socket_write($changedClientSocket, "You are already in the general chat room!\n");
                    }
                }
                else if ($data === "/quit") {
                    // Handle when the user wants to leave the chat server
                    changeRoom($changedClientSocket, "");
                    echo $nickname . " has left the chat server.\n"; // server log
                    unset($clients[$key]);
                    socket_close($changedClientSocket);
                    // Notify the rest of the server about the departure
                    foreach ($clients as $client) {
                        if($client === $sock) // skip the listening socket
                            continue;

                        socket_write($client, "\n* A user has left: " . $nickname . "\n");
                    }
                    break;
                }
                // Send message to all the clients (except the first one which is a listening socket)
                foreach ($clients as $send_sock) {
                
                    // Skip the listening socket or the client that we got the message from
                    if ($send_sock == $sock || $send_sock == $changedClientSocket)
                        continue;
                    
                    // Write the message to the clients (only if they are in the same room)
                    if ($serverData[$send_sock]["room"] == $serverData[$changedClientSocket]["room"]) {
                        if (strpos($data, "/join ") !== false) {
                            $roomName = substr($data, strlen("/join "));
                            socket_write($send_sock, "\n" . $nickname . " joined " . $roomName . "\n");
                        }
                        else {
                            socket_write($send_sock, "\n" . $nickname . ": " . $data . "\n");
                        }
                    }
                }

                // temp way to shutdown the chat process remotely
                if ($nickname === "admin" && $data === "/shutdown") {
                    return;
                }
            }
        }        
    }
} while (true);

socket_close($sock);

// Checks if the nickname already exists (nicknames are case insensitive)
// TODO: Check to disallow user to use "bad" words
function checkNickname($socket, $nickname) {
    global $sock, $serverData, $clients;
    echo "checking nickname => ".$nickname."\n";
    foreach($clients as $client) {
        if (isset($serverData[$client]) && strcasecmp($serverData[$client]["nickname"], $nickname) === 0) {
            socket_write($socket, "Nickname already taken. Please try another.\n");
            return;
        }
    }

    // Only get here if it's a unique nickname
    socket_write($socket, "Welcome ".$nickname."!\n");
    $serverData[$socket]["nickname"] = $nickname;
    $serverData[$socket]["state"] = STATE_CHAT;
    // announce to the rest of the new arrival
    foreach($clients as $client) {
        if ($client === $sock || $client === $socket)
            continue;

        socket_write($client, "\n* A new user has joined: " . $nickname . "\n");
    }
}

// Updates the room variable for a user.
// Also updates the room user count
// Returns the previous room name (if applicable)
function changeRoom($socket, $newRoom) {
    global $serverData, $rooms;

    $prevRoom = "";
    
    if (isset($serverData[$socket]["room"])) { // in case it's a new user who doesn't start with an existing room 
        $prevRoom = $serverData[$socket]["room"];
        $rooms[$prevRoom]--;
    }
    // Only set new room if there's a name, cos no name means user is leaving the server
    if ($newRoom !== "") {
        $serverData[$socket]["room"] = $newRoom;
        $rooms[$newRoom]++;
    }

    return $prevRoom;
}
?>
