<?php
require __DIR__ . '/bootstrap.php';
core_load();
require __DIR__ . '/../freedomapi-ai/includes/class-connection.php';
require __DIR__ . '/../freedomapi-ai/includes/class-openai.php';
$key = 'sk-test-abcdefghijklmnopqrstuvwxyz123456';
check(FreedomAPI_AI_Connection::available(), 'encryption supported');
$saved = FreedomAPI_AI_Connection::save($key, 'gpt-4.1-mini');
check(!is_wp_error($saved) && $saved['connected'], 'save encrypted key');
$record = get_user_meta(1, FreedomAPI_AI_Connection::META, true);
check(strpos(json_encode($record), $key) === false, 'no plaintext key persisted');
check(strpos(json_encode($saved), $key) === false, 'no plaintext key returned');
check(FreedomAPI_AI_Connection::secret() === $key, 'key round trip');
FreedomAPI_AI_Connection::save($key, 'gpt-4.1-mini');
check($record['ciphertext'] !== get_user_meta(1, FreedomAPI_AI_Connection::META, true)['ciphertext'], 'random nonce');
$GLOBALS['user_meta'][2][FreedomAPI_AI_Connection::META] = $record;
$GLOBALS['user'] = 2;
check(is_wp_error(FreedomAPI_AI_Connection::secret()), 'ciphertext bound to user');
$GLOBALS['user'] = 1;
$GLOBALS['salt'] = 'rotated-salt';
check(is_wp_error(FreedomAPI_AI_Connection::secret()), 'salt rotation fails closed');
unset($GLOBALS['salt']);
$GLOBALS['user_meta'][1][FreedomAPI_AI_Connection::META]['tag'] = base64_encode(str_repeat('x',16));
check(is_wp_error(FreedomAPI_AI_Connection::secret()), 'tampering detected');
FreedomAPI_AI_Connection::save($key, 'gpt-4.1-mini');
check(is_wp_error(FreedomAPI_AI_Connection::save("sk-secret\r\nInjected: true", 'gpt-4.1-mini')), 'header injection rejected');
$config = freedomapi_get_api_configuration(10);
$plan = plan(op('move', ['full_name'], ['name']));
$GLOBALS['http_response'] = ['response'=>['code'=>200], 'body'=>json_encode(['status'=>'completed', 'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($plan)]]]]])];
check(FreedomAPI_AI_OpenAI::generate('Rename name to full_name', $config) === $plan, 'structured response parsed');
[$url,$args] = end($GLOBALS['http_calls']);
check($url === 'https://api.openai.com/v1/responses' && $args['redirection'] === 0 && $args['sslverify'], 'fixed secure destination');
check($args['headers']['Authorization'] === 'Bearer ' . $key, 'current user key used');
$payload = json_decode($args['body'], true);
check($payload['store'] === false && $payload['text']['format']['strict'] === true, 'nonstored structured output');
$GLOBALS['http_response'] = new WP_Error('transport', 'secret key ' . $key);
$error = FreedomAPI_AI_OpenAI::generate('rename', $config);
check(is_wp_error($error) && !str_contains($error->message, $key), 'transport errors sanitized');
foreach ([401,429,500] as $status) {
    $GLOBALS['http_response'] = ['response'=>['code'=>$status], 'body'=>$key];
    $error = FreedomAPI_AI_OpenAI::generate('rename', $config);
    check(is_wp_error($error) && !str_contains($error->message, $key), 'provider error sanitized');
}
foreach ([['status'=>'incomplete'], ['status'=>'completed','output'=>[['type'=>'message','content'=>[['type'=>'refusal']]]]], ['status'=>'completed','output'=>[]]] as $body) {
    $GLOBALS['http_response'] = ['response'=>['code'=>200], 'body'=>json_encode($body)];
    check(is_wp_error(FreedomAPI_AI_OpenAI::generate('rename', $config)), 'bad generation rejected');
}
check(FreedomAPI_AI_Connection::disconnect()['connected'] === false, 'disconnect erases own credential');
check(isset($GLOBALS['user_meta'][2][FreedomAPI_AI_Connection::META]), 'other connection untouched');
echo "\nConnections: {$GLOBALS['checks']} checks passed.\n";
