<?php

/*
Plugin Name: ChatGPT Headline Generator
Description: Generates catchy headlines related to the keywords in a post's description
Version: 1.0
Author: Mondey
*/

add_action('admin_menu', 'chatgpt_headline_generator_menu');
add_action('save_post', 'chatgpt_generate_headlines');

function chatgpt_headline_generator_menu()
{
  add_options_page(
    'ChatGPT Headline Generator Settings',
    'ChatGPT Headline Generator',
    'manage_options',
    'chatgpt-headline-generator',
    'chatgpt_headline_generator_settings_page'
  );
}

function chatgpt_headline_generator_settings_page()
{
  if (!current_user_can('manage_options')) {
    wp_die('You do not have sufficient permissions to access this page.');
  }

  $api_key = get_option('chatgpt_headline_generator_api_key');

  if (isset($_POST['chatgpt_headline_generator_api_key'])) {
    $api_key = sanitize_text_field($_POST['chatgpt_headline_generator_api_key']);
    update_option('chatgpt_headline_generator_api_key', $api_key);
  }
?>

  <div class="wrap">
    <h1>ChatGPT Headline Generator Settings</h1>

    <form method="post">
      <table class="form-table">
        <tr>
          <th scope="row">
            <label for="chatgpt_headline_generator_api_key">API Key</label>
          </th>
          <td>
            <input type="text" id="chatgpt_headline_generator_api_key" name="chatgpt_headline_generator_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text">
          </td>
        </tr>
      </table>

      <p class="submit">
        <input type="submit" value="Save Changes" class="button-primary">
      </p>
    </form>
  </div>

<?php
}

// TODO: Missing updating through javascript metadata just updateds
function chatgpt_generate_headlines($post_id)
{
  if (wp_is_post_revision($post_id)) {
    return;
  }

  $post = get_post($post_id);

  // Check if the post is being deleted
  if ($post->post_status === 'trash') {
    return;
  }

  if ($post->post_title !== "" && strtolower($post->post_title) !== "generate title") {
    // Post already has Title, not generating Titles
    return;
  }

  $post_description = $post->post_content;

  $api_key = get_option('chatgpt_headline_generator_api_key');
  if (!$api_key || !$post_description) {
    return;
  }

  // Use OpenAI's ChatGPT API to generate headlines based on the post description
  $url = 'https://api.openai.com/v1/completions';
  $headers = array(
    'Content-Type' => 'application/json',
    'Authorization' => 'Bearer ' . $api_key,
  );
  $data = [
    'model' => "text-davinci-003",
    'max_tokens' => 300, // 64 or 100
    'prompt' => 'Generate 3 catchy headlines, ordered without formatting, related to the keywords in: "' . $post_description . '"',
    'temperature' => 0.5, // 0.7
    // "top_p" => 1,
    // "n" => 1,
    // "stream" => false,
    // "logprobs" => null,
    // "stop" => "\n",
  ];

  $stream = function ($curl_info, $data) {
    echo $data . "<br><br>";
    echo PHP_EOL;
    ob_flush();
    flush();
    return strlen($data);
  };

  $response = wp_remote_post(
    $url,
    [
      'headers' => $headers,
      'body' => json_encode($data),
      'httpversion' => '1.1',
      'redirection' => 10,
      // 'stream' => true,
      'timeout' => 60,
    ]
  );

  // Check for error
  if (is_wp_error($response)) {
    error_log('ChatGPT API request failed: ' . $response->get_error_message());
    return;
  }

  // Get the response data
  $result = json_decode(wp_remote_retrieve_body($response), true);
  // error_log('ChatGPT API response: ' . wp_remote_retrieve_body($response));
  // error_log('ChatGPT API result: ' . print_r($result, true));

  if (!isset($result['choices'][0]['text'])) {
    error_log('ChatGPT API response: ' . wp_remote_retrieve_body($response));
    error_log('ChatGPT API returned invalid data: ' . print_r($result, true));
    return;
  }

  // error_log('ChatGPT API result: ' . print_r($result['choices'][0]['text'], true));

  // Store the generated headlines as a post meta field
  update_post_meta($post_id, 'chatgpt_headlines', $result['choices'][0]['text']);
}

function chatgpt_add_meta_box()
{
  add_meta_box(
    'chatgpt_headlines',
    'ChatGPT Headlines',
    'chatgpt_headlines_callback',
    'post',
    'side',
    'default'
  );
}
add_action('add_meta_boxes', 'chatgpt_add_meta_box');

function chatgpt_headlines_callback($post)
{
  $headlines = get_post_meta($post->ID, 'chatgpt_headlines', true);
  echo '<textarea readonly style="width:100%; height:150px;">' . $headlines . '</textarea>';
}
