<?php

function filelinks_admin() {

  $settings = variable_get('filelinks_settings', array('show_filetype' => 1, 'show_filesize' => 1, 'show_dimensions' => 1, 'show_pagecount' => 1, 'show_playlength' => 1, 'usecurl' => 1, 'pdfinfo' => '/usr/local/bin/pdfinfo', 'paths' => array()));

  $form['intro'] = array('#value' => '<p>' . t('Specify the configuration for !url.', array('!url' => l(t('filelinks macros'), 'admin/help/filelinks'))) . '</p>');

  $form['filelinks_defaults'] = array(
    '#type' => 'fieldset',
    '#title' => t('Default Parameters'),
    '#collapsible' => FALSE,
	'#description' => t('These settings will be used for file display unless otherwise specified in the macro.'),
	'#tree' => FALSE,
  );
  $form['filelinks_defaults']['show_filetype'] = array(
    '#type' => 'checkbox',
    '#title' => t('Show file type'),
    '#default_value' => $settings['show_filetype'],
	'#description' => t('Macros may override this parameter with <i>show_filetype=1|0</i>.'),
  );
  $form['filelinks_defaults']['show_filesize'] = array(
    '#type' => 'checkbox',
    '#title' => t('Show file size'),
    '#default_value' => $settings['show_filesize'],
	'#description' => t('Macros may override this parameter with <i>show_filesize=1|0</i>.'),
  );
  $form['filelinks_defaults']['show_dimensions'] = array(
    '#type' => 'checkbox',
    '#title' => t('Show image dimensions'),
    '#default_value' => $settings['show_dimensions'],
	'#description' => t('Macros may override this parameter with <i>show_dimensions=1|0</i>.'),
  );
  
  $form['filelinks_defaults']['show_pagecount'] = array(
    '#type' => 'checkbox',
    '#title' => t('Show page count (pdfs)'),
    '#default_value' => $settings['show_pagecount'],
	'#description' => t('Macros may override this parameter with <i>show_pagecount=1|0</i>.'),
  );

  $form['filelinks_defaults']['show_playlength'] = array(
    '#type' => 'checkbox',
    '#title' => t('Show play length (mp3s)'),
    '#default_value' => $settings['show_playlength'],
	'#description' => t('Macros may override this parameter with <i>show_playlength=1|0</i>.'),
  );

  if( $settings['pdfinfo'] && ! file_exists($settings['pdfinfo']) ) {
    $status .= '<b>' . t('This path is invalid.') . '</b>';
  }
  else {
    $status = '';
  }

  $form['pdfinfo'] = array(
    '#type' => 'textfield',
	'#title' => t('Path to pdfinfo'),
	'#default_value' => $settings['pdfinfo'],
	'#description' => t('The system path to the !link program used to retrieve PDF document information. This path must be valid for filelinks to add page counts. !status',
	   array('!link' => l('pdfinfo', 'http://linuxcommand.org/man_pages/pdfinfo1.html'), '!status' => $status)),
  );

  $form['paths'] = array(
    '#type' => 'fieldset',
	'#title' => t('Search paths'),
	'#description' => t('Search paths specify the systems paths in which to search for linked files. See !url for more information. Delete all of a search path\'s fields to remove it.',  array('!url' => l(t('help'), 'admin/help/filelinks'))),
  );

  $i = 0;
  foreach($settings['paths'] as $path) {
	$item = array(
	  "path_tag_$i" => array('#type' => 'textfield', '#size' => 20, '#default_value' => $path['tag']),
	  "path_path_$i" => array('#type' => 'textfield', '#size' => 20, '#default_value' => $path['path']),
	  "path_url_$i" => array('#type' => 'textfield', '#size' => 20, '#default_value' => $path['url']),
	);
	$form['paths'][] = $item;
	$i++;
  }

  // Add another item so they can add a new one
  $item = array(
	"path_tag_$i" => array('#type' => 'textfield', '#size' => 20),
	"path_path_$i" => array('#type' => 'textfield', '#size' => 20),
	"path_url_$i" => array('#type' => 'textfield', '#size' => 20),
  );
  $form['paths'][] = $item;

  $form['usecurl'] = array(
    '#type' => 'checkbox',
	'#title' => t('Use curl to fetch attributes for remote files'),
	'#description' => t('Filelinks can access and display limited meta data for files on remote servers, that you specify with a full url such as %sample_url. Check this box if you want to use this feature.',
	  array('%sample_url' => 'http://mycdn.com/file.pdf')),
    '#default_value' => $settings['usecurl'],
  );

  $form['submit'] = array('#type' => 'submit', '#value' => t('Save configuration'));

  return $form;
}

function theme_filelinks_admin($form) {

  $rows[] = array();
  $i = 0;
  foreach(element_children($form['paths']) as $id) {
    $rows[] = array(
	  drupal_render($form['paths'][$id]["path_tag_$i"]),
	  drupal_render($form['paths'][$id]["path_path_$i"]),
	  drupal_render($form['paths'][$id]["path_url_$i"]),
	);
	$i++;
  }

  $row = array_pop($rows);
  $rows[] = array(array('data' => t('Add a new search path:'), 'colspan' => 3));
  $rows[] = $row;

  $table = theme('table', array(t('Tag'), t('Location'), t('URL')), $rows);
  $form['paths']['#value'] = $table;
  return drupal_render($form);
}

function filelinks_admin_validate($form, &$form_state) {

  $tags = array();
  for($i=0;isset($form_state['values']["path_tag_$i"]);$i++) {
	$tag = trim($form_state['values']["path_tag_$i"]);
	$url = trim($form_state['values']["path_url_$i"]);
	$path = trim($form_state['values']["path_path_$i"]);
	if( ($url || $path) && ! $tag ) {
	  form_set_error("path_tag_$i", t('Each search path must have a  unique tag.  Delete all fields for a search path to remove the path.'));
	}

	if( $tag ) {
	  if( $tags[$tag] ) {
		form_set_error("path_tag_$i", t('Tags must be unique.'));
	  }

	  $tags[$tag] = $tag;
	}
  }
}

function filelinks_admin_submit($form, &$form_state) {

  $settings = array('paths' => array());
  foreach(array('show_filetype', 'show_filesize', 'show_dimensions', 'show_pagecount', 'show_playlength', 'pdfinfo', 'usecurl') as $key) {
    $settings[$key] = $form_state['values'][$key];
  }

  for($i=0;isset($form_state['values']["path_tag_$i"]);$i++) {
	$tag = trim($form_state['values']["path_tag_$i"]);
	$url = trim($form_state['values']["path_url_$i"]);
	$path = trim($form_state['values']["path_path_$i"]);
    if( $tag ) {
	  $settings['paths'][] = array('tag' => $tag, 'path' => $path, 'url' => $url);
	}
  }

  variable_set('filelinks_settings', $settings);
  drupal_set_message('Configuration has been saved.');
}
