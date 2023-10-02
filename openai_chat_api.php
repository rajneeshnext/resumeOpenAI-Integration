<?php
/*
  https://github.com/alexz006/ChatGPT-Example
*/

set_time_limit(120);

// To use the official API (GPT-3 and GPT-3.5 turbo)
// get OPENAI_API_KEY https://platform.openai.com/account/api-keys
define('OPENAI_API_KEY', 'sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');

// To use chat.openai.com
// get cookie "_puid=user-..." - https://chat.openai.com/chat
define('COOKIE_PUID', '_puid=user-...');

// To use chat.openai.com
// get ACCESS_TOKEN - https://chat.openai.com/api/auth/session
define('ACCESS_TOKEN', '');

if($_SERVER['REQUEST_METHOD'] == "POST"){
  $arr = json_decode(file_get_contents('php://input'), true);
  if(!empty($arr['message'])){
    header('Content-Type: application/json');
    
    $openai = [];
    
    if($arr['openai_type'] == 'chat'){ // chat.openai.com
      $arr['conversation_id'] = $arr['conversation_id'] ?? '';
      $arr['parent_message_id'] = $arr['parent_message_id'] ?? uuid4();
      $openai = openai_chat($arr);
    }
    
    elseif($arr['openai_type'] == 'turbo')// gpt-3.5-turbo
      $openai = openai_api_gpt_35_turbo($arr['message']);
    
    else // gpt-3
      $openai = openai_api_text_davinci_003($arr['message']);
    
    if(!empty($openai['message']))
      $openai["message"] = replace_html(trim($openai["message"]));
    
    die(json_encode($openai));
  }
}

function openai_chat($arr){
  
  $url = 'https://chat.openai.com/backend-api/conversation';

  $data = [
    "action" => "next",
    "messages" => [
      [
        "content" => [
          "content_type" => "text",
          "parts" => [$arr['message']]
        ],
        "id" => uuid4(),
        "role" => "user"
      ]
    ],
    /*
     text-davinci-002-render-sha - default
     text-davinci-002-render-paid - legacy
     gpt-4
    */
    "model" => $arr['chat_model'],
    "parent_message_id" => $arr['parent_message_id']
  ];
  
  if(!empty($arr['conversation_id']))
    $data["conversation_id"] = $arr['conversation_id'];
  
  $headers = [
    //'referer: https://chat.openai.com/chat' .($arr['conversation_id']?'/'.$arr['conversation_id']:''),
    'cookie: ' . COOKIE_PUID,
    'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36',
    'Content-Type: application/json',
    'Authorization: Bearer ' . ACCESS_TOKEN
  ];
  
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $response = curl_exec($ch);
  if (curl_errno($ch))
    return ["error" => ["msg"=>'<span style="color:red">' . curl_error($ch) . '</span>']];
  curl_close($ch);
  
  $expl = explode("\n", $response);
  $last_line = '';
  foreach ($expl as $line) {
    if($line === "" || $line === null)
      continue;
    if(strpos($line, "data: ") !== false)
      $line = substr($line, 6);
    if($line === "[DONE]")
      break;
    $last_line = $line;
  }
 
  if($last_line){
    $decoded_line = json_decode($last_line, true);
    if (json_last_error() !== JSON_ERROR_NONE)
      return ["error" => ["msg"=>'<span style="color:red">' . $last_line . '</span>']];
    
    if(!empty($decoded_line["detail"]))
      return ["error" => (
        is_array($decoded_line["detail"])?
          (!empty($decoded_line["detail"]["message"])?
      ["msg"=>'<span style="color:red">' . $decoded_line["detail"]["message"] . '</span>']:
      $decoded_line["detail"][0]):
          ["msg"=>'<span style="color:red">' . $decoded_line["detail"] . '</span>']
      )];
    
    $message = $decoded_line["message"]["content"]["parts"][0];
    $conversation_id = $decoded_line["conversation_id"];
    $parent_message_id = $decoded_line["message"]["id"];
    return [
      "message" => $message,
      "conversation_id" => $conversation_id,
      "parent_message_id" => $parent_message_id,
    ];
  }
  return ["error" => ["msg"=> '<span style="color:red">unknown</span>']];
}

function openai_api_text_davinci_003($message){
  
  $url = 'https://api.openai.com/v1/engines/text-davinci-003/completions';
  $headers = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . OPENAI_API_KEY
  ];
  $data = [
    "prompt" => $message,
    'max_tokens' => 2000,
    "temperature" => 0.5,
  ];
  
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $response  = curl_exec($ch);
  if (curl_errno($ch)) {
    $return = [
      'error' => ['msg'=>'<span style="color:red">' . curl_error($ch) . '</span>']
    ];
    curl_close($ch);
    return $return;
  }
  curl_close($ch);
  $arr = json_decode($response, 1);

  $return = [
    'message' => ''
  ];
  if(!empty($arr['error'])){
    $return['error'] = ['msg'=>'<span style="color:red">' . $arr['error']['message'] . '</span>'];
  }
  elseif(!empty($arr['choices'])){
    $return['message'] = trim($arr['choices'][0]['text']);
  }
  return $return;
}

function openai_api_gpt_35_turbo($message){
  
  $url = 'https://api.openai.com/v1/chat/completions';
  $headers = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . OPENAI_API_KEY
  ];
  $data = [
    "model" => "gpt-3.5-turbo",
    "messages" => [
    ["role" => "user", "content" => $message]
  ],
    'max_tokens' => 2000,
    "temperature" => 0.5,
  ];
  
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $response  = curl_exec($ch);
  if (curl_errno($ch)) {
    $return = [
      'error' => ['msg'=>'<span style="color:red">' . curl_error($ch) . '</span>']
    ];
    curl_close($ch);
    return $return;
  }
  curl_close($ch);
  $arr = json_decode($response, 1);

  $return = [
    'message' => ''
  ];
  if(!empty($arr['error'])){
    $return['error'] = ['msg'=>'<span style="color:red">' . $arr['error']['message'] . '</span>'];
  }
  elseif(!empty($arr['choices'])){
    $return['message'] = trim($arr['choices'][0]['message']['content']);
  }
  return $return;
}

/*--------------------*/

// get uuid4key

function uuid4() {
  $data = openssl_random_pseudo_bytes(16);
  $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
  $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// replace_html
function replace_html($string) {
  
  // find_markdown
  preg_match_all('/(?:```|`)(.*?)(?:```|`)/s', $string, $find_markdown);
  foreach($find_markdown[1] as $i=>$find){
    $string = preg_replace('~'.preg_quote($find).'~', "-find_markdown{$i}-", $string, 1);
  }
  
  if(empty($find_markdown[1])){
    
    // find_php
    preg_match_all('/(<\?(?:[^\s\n]*)?[\s\n]+.*?\?>)/s', $string, $find_php);
    foreach($find_php[1] as $i=>$find){
      $string = preg_replace('~'.preg_quote($find).'~', "-find_php{$i}-", $string, 1);
  }
      
    // htmlspecialchars other code
    $string = htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    $string = nl2br($string);
    
    // return find_php and highlight_string
    preg_match_all('/(-find_php[0-9]+?-)/s', $string, $m);
    foreach($m[1] as $i=>$find){
      $find_php[1][$i] = highlight_string($find_php[1][$i], true);
      $string = preg_replace('~'.preg_quote($find).'~', $find_php[1][$i], $string, 1);
    }
    
  }
  else {
    
    // return find_markdown
    preg_match_all('~(?:```|`)(-find_markdown[0-9]+?-)(?:```|`)~s', $string, $m);
    foreach($m[1] as $i=>$find){
      $string = preg_replace('~'.preg_quote($find).'~', $find_markdown[1][$i], $string, 1);
  }
    
  }
  return $string;
}
