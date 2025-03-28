<?php

ini_set('max_execution_time', '1700');
set_time_limit(1700);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

error_reporting(E_ERROR);
ini_set('display_errors', 1);

function send_bearer($url, $token, $type = "GET", $param = []){
  $descriptor = curl_init($url);
  if ($type != "GET") {
    curl_setopt($descriptor, CURLOPT_POSTFIELDS, json_encode($param));
  }
  curl_setopt($descriptor, CURLOPT_RETURNTRANSFER, 1);
  if (stripos($url, "openai") !== false) {
    curl_setopt($descriptor, CURLOPT_HTTPHEADER, array("User-Agent: M-Soft Integration", "Content-Type: application/json", "OpenAI-Beta: assistants=v2", "Authorization: Bearer ".$token));
  } else {
    curl_setopt($descriptor, CURLOPT_HTTPHEADER, array("User-Agent: M-Soft Integration", "Content-Type: application/json", "Authorization: Bearer ".$token));
  }
  curl_setopt($descriptor, CURLOPT_CUSTOMREQUEST, $type);
  $itog = curl_exec($descriptor);
  curl_close($descriptor);
  return $itog;
}

$ssToken = "";
$gptToken = "";

$input = json_decode(file_get_contents("php://input"), true);
$result["state"] = true;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  // Валідація вхідних даних
  if ($input == NULL) {
    $result["state"] = false;
    $result["error"]["message"][] = "Тіло запиту не отримане. Вкажіть дані згідно інструкцій на вкладці 'Тіло' у форматі JSON";
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
  }
  if ($input["assistantId"] == NULL) {
    $result["state"] = false;
    $result["error"]["message"][] = "параметр 'assistantId' є обов'язковим. Додайте його в тілі запиту";
  }
  if ($input["userId"] == NULL) {
    $result["state"] = false;
    $result["error"]["message"][] = "Параметр 'userId' є обов'язковим. Додайте його в тілі запиту";
  }
  if ($result["state"] == false) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
  }
  // Отримання вмісту запиту
  if ($input["message"] == NULL) {
    // Пошук останього повідомлення від користувача
    $getMessages = json_decode(send_bearer("https://api.smartsender.com/v1/contacts/".$input["userId"]."/messages?page=1&limitation=20", $ssToken), true);
    if ($getMessages["collection"] == NULL) {
      $result["state"] = false;
      $result["error"]["message"][] = "Помилка отримання історії повідомлень користувача";
      $result["error"]["smartsender"] = $getMessages;
      echo json_encode($result, JSON_UNESCAPED_UNICODE);
      exit;
    }
    foreach ($getMessages["collection"] as $oneMessage) {
      if ($oneMessage["sender"]["type"] == "contact" && $oneMessage["content"]["type"] == "text" && $oneMessage["content"]["resource"]["parameters"]["content"] != NULL) {
        $useMessage = $oneMessage["content"]["resource"]["parameters"]["content"];
        break;
      }
    }
    if ($useMessage == NULL) {
      $result["state"] = false;
      $result["error"]["message"][] = "Серед останіх повідомлень в історії не було знайдено повідомлення від користувача";
      echo json_encode($result, JSON_UNESCAPED_UNICODE);
      exit;
    }
  } else {
    $useMessage = $input["message"];
  }
  $result["requestMessage"] = $useMessage;
  // Створення/отримання потоку потоку
  if (file_exists("threads") != true) {
    mkdir("threads");
  }
  if (file_exists("threads/".$input["userId"])) {
    $thread = file_get_contents("threads/".$input["userId"]);
  } else {
    $createThread = json_decode(send_bearer("https://api.openai.com/v1/threads", $gptToken, "POST", []), true);
    if ($createThread["id"] != NULL) {
      file_put_contents("threads/".$input["userId"], $createThread["id"]);
      $thread = $createThread["id"];
    } else {
      $result["state"] = false;
      $result["error"]["message"][] = "Помилка створення потоку для асистента";
      $result["error"]["assist"] = $createThread;
      echo json_encode($result, JSON_UNESCAPED_UNICODE);
      exit;
    }
  }
  // Запуск обробки потоку
  $toRun = [
    "assistant_id" => $input["assistantId"],
    "additional_messages" => [[
      "role" => "user",
      "content" => $useMessage,
    ]]
  ];
  if ($input["instructions"] != NULL && mb_strlen($input["instructions"]) > 5) {
    $toRun["instructions"] = $input["instructions"];
  }
  if ($input["additional_instructions"] != NULL && mb_strlen($input["additional_instructions"]) > 5) {
    $toRun["additional_instructions"] = $input["additional_instructions"];
  }
  $runThreads = json_decode(send_bearer("https://api.openai.com/v1/threads/".$thread."/runs", $gptToken, "POST", $toRun), true);
  if ($runThreads["id"] == NULL) {
    $result["state"] = false;
    $result["error"]["message"][] = "Помилка запуску обробки потоку";
    $result["error"]["assist"] = $runThreads;
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
  }
  // Перевірка статусу обробки
  if ($input["timeLimit"] != NULL) {
    $timeLimit = $input["timeLimit"];
  } else {
    $timeLimit = 100;
  }
  for ($i=0; $i<=$timeLimit; $i++) {
    $getRun = json_decode(send_bearer("https://api.openai.com/v1/threads/".$thread."/runs/".$runThreads["id"], $gptToken), true);
    if ($getRun["status"] == "queued" || $getRun["status"] == "in_progress") {
      // В процесі обробки
      sleep(1);
      continue;
    } else if ($getRun["status"] == "completed") {
      // Успішно оброблено
      break;
    } else {
      // Щось пішло не так
      $result["state"] = false;
      $result["error"]["message"][] = "В процесі обробки потоку асистентом щось пішло не так.";
      $result["error"]["message"][] = "Статус обробки встановлено на: '".$getRun["status"]."'";
      $result["error"]["assist"] = $getRun;
      echo json_encode($result, JSON_UNESCAPED_UNICODE);
      exit;
    }
  }
  if ($i == $timeLimit && $getRun["status"] != "completed") {
    // Відміна обробки через вичерпаний час
    $cancelRun = json_decode(send_bearer("https://api.openai.com/v1/threads/".$thread."/runs/".$runThreads["id"]."/cancel", $gptToken, "POST"), true);
  }
  // Отримання результату обробки запиту
  $getThread = json_decode(send_bearer("https://api.openai.com/v1/threads/".$thread."/messages", $gptToken), true);
  if ($getThread["data"] == NULL) {
    $result["state"] = false;
    $result["error"]["message"][] = "Виникла помилка при отриманні результату обробки";
    $result["error"]["assist"] = $getThread;
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
  }
  if ($getThread["data"][0]["role"] != "assistant") {
    $result["state"] = false;
    $result["error"]["message"][] = "Відповідь асистента відсутня. Використовуємо стандартну відповідь";
    if ($input["failedMessage"] != NULL) {
      $answerMessage = $input["failedMessage"];
    } else {
      $answerMessage = "Нажаль, я не можу відповісти. Спробуйте пізніше, або перефразуйте Ваше повідомлення";
    }
  } else {
    if ($input["answerMessage"] != NULL && stripos($input["answerMessage"], "%answer%") !== false) {
      $answerMessage = str_ireplace("%answer%", $getThread["data"][0]["content"][0]["text"]["value"], $input["answerMessage"]);
    } else {
      $answerMessage = $getThread["data"][0]["content"][0]["text"]["value"];
    }
  }
  $result["answerMessage"] = $answerMessage;
  // Відправка відповіді користувачу в SmartSender
  $sendMessage = json_decode(send_bearer("https://api.smartsender.com/v1/contacts/".$input["userId"]."/send", $ssToken, "POST", [
    "type" => "text",
    "watermark" => 1,
    "content" => $answerMessage
  ]), true);
  if ($sendMessage["id"] == NULL) {
    $result["state"] = false;
    $result["error"]["message"][] = "Помилка відправки відповіді користувачу";
    $result["error"]["smartsender"] = $sendMessage;
  }
} else {
  $result["state"] = false;
  $result["error"]["message"][] = "Використовуйте тип запиту POST";
}


echo json_encode($result, JSON_UNESCAPED_UNICODE);
