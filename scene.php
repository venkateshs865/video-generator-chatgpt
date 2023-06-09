<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

// Initialize the logger
$log = new Logger('scene');
$log->pushHandler(new StreamHandler('logs/scene.log', Logger::DEBUG));
$log->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// Initialize AppConfig
$appConfig = new AppConfig($log);

// Retrieve API keys from AppConfig
$openaiApiKey = $appConfig->getApiKey('OpenAI');
$elevenLabsApiKey = $appConfig->getApiKey('ElevenLabsApi');

// Initialize OpenAI and ElevenLabsApi objects with the API keys and logger
$openai = new OpenAI($openaiApiKey, null, $log);
$elevenLabsApi = new ElevenLabsApi($elevenLabsApiKey, null, $log);

$log_data = [];
$prompt = 'Something short and gentle about india';

// Generate script if it does not exist
$script_file = __DIR__ . '/scripts/' . md5($prompt) . '.txt';
if (!file_exists($script_file)) {
	$log->info('Generating script...');
	$role = 'you are a scriptwriter from William S Burroughs era. respond as he would.';
	$script = $openai->generateScript($role, $prompt);
	file_put_contents($script_file, $script);
} else {
	$log_data['txtprompt_search'] = true;
	$script = file_get_contents($script_file);
}
$log->info('Script: ' . $script);

// Generate image prompt if it does not exist
$image_prompt_file = __DIR__ . '/image_prompts/' . md5($prompt) . '.txt';
if (!file_exists($image_prompt_file)) {
	$log->info('Generating image prompt...');
	$role = 'you are a brilliant AI prompt writer. create an image prompt based on this script.';
	$image_prompt = $openai->generateScript($role, $script);
	file_put_contents($image_prompt_file, $image_prompt);
} else {
	$image_prompt = file_get_contents($image_prompt_file);
	$log_data['imgprompt_search'] = true;
}
$log->info('Image Prompt: ' . $image_prompt);

$audio_file = __DIR__ . '/voices/' . md5($prompt) . '.mp3';
if (!file_exists($audio_file)) {
	$log->info('Generating audio...');
	$audio_data = [
		'text' => $script,
		'voiceId' => '21m00Tcm4TlvDq8ikWAM'
	];
	$audio_response = $elevenLabsApi->textToSpeechWithVoiceId($audio_data['voiceId'], $audio_data);
	file_put_contents($audio_file, $audio_response->getBody());
} else {
	$log_data['audio_cache'] = true;
}

// Calculate the duration of the audio file
$log->info('Calculating audio duration...');
$getID3 = new getID3;
$file_info = $getID3->analyze($audio_file);
$audio_duration = $file_info['playtime_seconds'];

$seconds_per_image = 6;
$frames_per_second = 25;
$frames_per_image = $seconds_per_image * $frames_per_second;
$number_of_images = intval($audio_duration / $seconds_per_image);

$log->info('Creating ' . $number_of_images . ' images for a ' . $audio_duration . ' second audio clip!');

// Generate images if they do not exist
$images_dir = __DIR__ . '/images/' . md5($prompt);
if (!file_exists($images_dir)) {
	$log->info('Generating images...');
	mkdir($images_dir);
	$images = $openai->generateImage($image_prompt, __DIR__ . DIRECTORY_SEPARATOR . 'images/' . md5($prompt), '1024x1024', $number_of_images);
	$log_data['images'] = $images;
} else {
	$images = [];
	$imagesPath = $images_dir;
	$log->info('Checking imagesPath ' . $imagesPath);
	foreach (glob($imagesPath . '/*.png') as $image) {
		$images[] = $image;
	}
	$log_data['image_search'] = true;
}

// Create MeltProject
$log->info('Begin the melty.');
$project = new MeltProject($log, 1920, 1080, $frames_per_second);

// Add images to project
$log->info('Adding images to project...');
foreach ($images as $image) {
	$log->info('Adding image ' . $image);
	$project->addImage($image, 0, $frames_per_image);
}

// Add audio to project
$log->info('Adding audio to project...');
$project->setVoiceover($audio_file);
$xml = $project->generateXml();

// Save project
$log->info('Saving project to scene.xml...');
$xml->save('scene.xml');
$log->info('End the melt.');

// Log data
$log->info('Data:', $log_data);
