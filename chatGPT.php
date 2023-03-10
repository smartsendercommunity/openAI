<?php

ini_set('max_execution_time', '1700');
set_time_limit(1700);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

// Settings
$sstoken = "";
$gpttoken = "";

$input = json_decode(file_get_contents("php://input"), true);
$st = time();

function send_bearer($url, $token, $type = "GET", $param = []){
    $descriptor = curl_init($url);
     curl_setopt($descriptor, CURLOPT_POSTFIELDS, json_encode($param));
     curl_setopt($descriptor, CURLOPT_RETURNTRANSFER, 1);
     curl_setopt($descriptor, CURLOPT_HTTPHEADER, array("User-Agent: M-Soft Integration", "Content-Type: application/json", "Authorization: Bearer ".$token)); 
     curl_setopt($descriptor, CURLOPT_CUSTOMREQUEST, $type);
    $itog = curl_exec($descriptor);
    curl_close($descriptor);
    return $itog;
}

if ($input["userId"] == NULL) {
    $result["state"] = false;
    $result["error"]["message"][] = "'userId' is missing";
}
if ($input["request"] == NULL) {
    $result["state"] = false;
    $result["error"]["message"][] = "'request' is missing";
}
if ($input["response"] == NULL) {
    $result["state"] = false;
    $result["error"]["message"][] = "'response' is missing";
} else if (mb_strpos($input["response"], "%result%") === false) {
    $result["state"] = false;
    $result["error"]["message"][] = "'response' must contain %result%";
}
if ($result["state"] === false) {
    echo json_encode($result);
    exit;
}

if (mb_stripos($input["request"], "%text%") !== false) {
    $getMessage = json_decode(send_bearer("https://api.smartsender.com/v1/contacts/".$input["userId"]."/messages?page=1&limitation=20", $sstoken), true);
    if ($getMessage["collection"] != NULL && is_array($getMessage["collection"])) {
        foreach ($getMessage["collection"] as $oneMessage) {
            if ($oneMessage["content"]["type"] == "text" && $oneMessage["sender"]["type"] == "contact") {
                $input["request"] = str_ireplace("%text%", $oneMessage["content"]["resource"]["parameters"]["content"], $input["request"]);
                break;
            }
        }
    }
}

$request["model"] = "gpt-3.5-turbo";
if (file_exists("chats/".$input["userId"].".json") && $input["clearChat"] !== true) {
    $request["messages"] = json_decode(file_get_contents("chats/".$input["userId"].".json"), true);
}
$request["messages"][] = [
    "role" => "user",
    "content" => $input["request"]
];
$request["user"] = "ssId:".$input["userId"];

$response = json_decode(send_bearer("https://api.openai.com/v1/chat/completions", $gpttoken, "POST", $request), true);

if ($response["choices"][0] != NULL) {
    $request["messages"][] = $response["choices"][0]["message"];
    if (file_exists("chats") != true) {
        mkdir("chats");
    }
    file_put_contents("chats/".$input["userId"].".json", json_encode($request["messages"], JSON_UNESCAPED_UNICODE));

    $t = time() - $st;
    $send["type"] = "text";
    $send["watermark"] = 1;
    $send["content"] = str_ireplace(["%time%", "%result%"], [$t."??????", $response["choices"][0]["message"]["content"]], $input["response"]);

    $ssr = json_decode(send_bearer("https://api.smartsender.com/v1/contacts/".$input["userId"]."/send", $sstoken, "POST", $send), true);
}


$l["input"] = $input;
$l["request"] = $request;
$l["response"] = $response;
$l["sss"] = $send;
$l["ssr"] = $ssr;
echo json_encode($l);