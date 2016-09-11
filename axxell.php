<?php

/*
 * 2007-2016 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2016 PrestaShop SA
 * @license http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */
if (! defined ( '_PS_VERSION_' ))
	exit ();
class Axxell extends Module {
	private $axxellClient;
	private $logger;
	private $accessKey;
	private $enabled;
	public function __construct() {
		$this->name = 'axxell';
		$this->tab = 'front_office_features';
		$this->version = '1.0.0';
		$this->author = 'Axxell';
		$this->need_instance = 0;
		
		$this->bootstrap = true;
		parent::__construct ();
		
		$this->displayName = $this->l ( 'Product recommendations block' );
		$this->description = $this->l ( 'Adds a block displaying recommended products.' );
		$this->ps_versions_compliancy = array (
				'min' => '1.6',
				'max' => '1.6.99.99' 
		);
		
		$this->configAccessKeyLabel = "AXXELL_CONFIG_ACCESS_KEY";
		$this->configSecretKeyLabel = "AXXELL_CONFIG_SECRET_KEY";
		$this->configEndpointURLLabel = "AXXELL_CONFIG_ENDPOINT_URL";
		$this->configMaxItemsLabel = "AXXELL_CONFIG_MAX_ITEMS";
		$this->configEngineTypeLabel = "AXXELL_CONFIG_ENGINE_TYPE";
		$this->configSynchronizeLabel = "AXXELL_CONFIG_SYNCHRONIZE";
		$this->configEnabledLabel = "AXXELL_CONFIG_ENABLED";
		
		$this->configLabels = array (
				$this->configAccessKeyLabel,
				$this->configSecretKeyLabel,
				$this->configEndpointURLLabel,
				$this->configMaxItemsLabel,
				$this->configEngineTypeLabel,
				$this->configSynchronizeLabel,
				$this->configEnabledLabel 
		);
		
		$this->engineTypes = array (
				array (
						'name' => 'personalized',
						'label' => 'Personalized' 
				),
				array (
						'name' => 'similar',
						'label' => 'Similar' 
				) 
		);
	}
	public function install() {
		if (! parent::install () || ! $this->registerHook ( 'header' ) || ! $this->registerHook ( 'displayFooterProduct' ) || ! $this->registerHook ( 'actionProductSave' ) || ! $this->registerHook ( 'actionProductDelete' ) || ! $this->registerHook ( 'actionValidateOrder' ) || ! Configuration::updateValue ( $this->configEnabledLabel, false )) {
			return false;
		}
		$this->_clearCache ( 'axxell.tpl' );
		
		return true;
	}
	public function uninstall() {
		$this->_clearCache ( 'axxell.tpl' );
		if (! parent::uninstall ()) {
			return false;
		}
		foreach ( $this->configLabels as $label ) {
			if (! Configuration::deleteByName ( $label ))
				return false;
		}
		
		return true;
	}
	public function getContent() {
		$output = '';
		if (Tools::isSubmit ( 'submitAxxell' )) {
			$allSet = true;
			foreach ( $this->configLabels as $label ) {
				$value = Tools::getValue ( $label );
				if (is_int($value) || $value == 0)
					continue;
				if (! ($value) || empty ( $value )) {
					$output .= $label . " is not set.";
					$allSet = false;
				}
			}
			if (! $allSet)
				$output .= $this->displayError ( $this->l ( 'All fields must be set' ) );
			else {
				foreach ( $this->configLabels as $label ) {
					$value = Tools::getValue ( $label );
					Configuration::updateValue ( $label, $value );
				}
				$synchronize = Tools::getValue ( $this->configSynchronizeLabel );
				if ($synchronize == true) {
					$this->synchronize ();
				}
				$output .= $this->displayConfirmation ( $this->l ( 'Settings updated.' ) );
				Configuration::updateValue ( $this->configSynchronizeLabel, false );
			}
		}
		return $output . $this->renderForm ();
	}
	public function hookActionValidateOrder($params) {
		$logger = $this->getLogger ();
		$order = $params ['order'];
		$products = $order->getProducts ();
		$client = $this->getAxxellClient ();
		$logger->logDebug ( "order validated" );
		foreach ( $products as $product ) {
			$this->registerEvent ( "purchase", $product ['product_id'] );
		}
		return true;
	}
	public function hookActionProductSave($params) {
		$this->registerProduct ( $params ['id_product'] );
	}
	public function hookActionProductDelete($product_id) {
		$this->deregisterProduct ( $product_id );
	}
	public function renderWidget($params) {
		$logger = $this->getLogger();
		$product_id = $params['product']->id;
		$productIds = $this->getRecommendations(null, $product_id);
		$displayCount = $this->getItemsCount();
		if (count($productIds) < $displayCount) {
			$randomProductIds = Db::getInstance ( _PS_USE_SQL_SLAVE_ )->executeS ( '
				SELECT p.id_product FROM ' . _DB_PREFIX_ . 'product p ' .
				'ORDER BY RAND()' .
				'LIMIT '. $displayCount );
			for ($i = count($productIds); $i < $displayCount; $i++) {
				$randomProductId = $randomProductIds[$i]['id_product'];
				$logger->logDebug("Backfilling with random product: " . $randomProductId);
				$productIds[] = $randomProductId;
			}
		}
		
		$defaultCover = Language::getIsoById ( $params ['cookie']->id_lang ) . '-default';
		$productIdsString = implode(',', array_map('intval', $productIds));
		
		$productsImages = Db::getInstance ( _PS_USE_SQL_SLAVE_ )->executeS ( '
		SELECT MAX(image_shop.id_image) id_image, p.id_product, il.legend, product_shop.active, pl.name, pl.description_short, pl.link_rewrite, cl.link_rewrite AS category_rewrite
		FROM ' . _DB_PREFIX_ . 'product p
		' . Shop::addSqlAssociation ( 'product', 'p' ) . '
		LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl ON (pl.id_product = p.id_product' . Shop::addSqlRestrictionOnLang ( 'pl' ) . ')
		LEFT JOIN ' . _DB_PREFIX_ . 'image i ON (i.id_product = p.id_product)' . Shop::addSqlAssociation ( 'image', 'i', false, 'image_shop.cover=1' ) . '
		LEFT JOIN ' . _DB_PREFIX_ . 'image_lang il ON (il.id_image = image_shop.id_image AND il.id_lang = ' . ( int ) ($params ['cookie']->id_lang) . ')
		LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl ON (cl.id_category = product_shop.id_category_default' . Shop::addSqlRestrictionOnLang ( 'cl' ) . ')
		WHERE p.id_product IN (' . $productIdsString . ')
		AND pl.id_lang = ' . ( int ) ($params ['cookie']->id_lang) . '
		AND cl.id_lang = ' . ( int ) ($params ['cookie']->id_lang) . '
		GROUP BY product_shop.id_product' );
		
		$productsImagesArray = array ();
		foreach ( $productsImages as $pi )
			$productsImagesArray [$pi ['id_product']] = $pi;
		
		$productsObj = array ();
		foreach ( $productIds as $productId) {
			$obj = ( object ) 'Product';
			if (! isset ( $productsImagesArray [$productId] ) || (! $obj->active = $productsImagesArray [$productId] ['active']))
				continue;
			else {
				$obj->id = ( int ) ($productsImagesArray [$productId] ['id_product']);
				$obj->id_image = ( int ) $productsImagesArray [$productId] ['id_image'];
				$obj->cover = ( int ) ($productsImagesArray [$productId] ['id_product']) . '-' . ( int ) ($productsImagesArray [$productId] ['id_image']);
				$obj->legend = $productsImagesArray [$productId] ['legend'];
				$obj->name = $productsImagesArray [$productId] ['name'];
				$obj->description_short = $productsImagesArray [$productId] ['description_short'];
				$obj->link_rewrite = $productsImagesArray [$productId] ['link_rewrite'];
				$obj->category_rewrite = $productsImagesArray [$productId] ['category_rewrite'];
				// $obj is not a real product so it cannot be used as argument for getProductLink()
				$obj->product_link = $this->context->link->getProductLink ( $obj->id, $obj->link_rewrite, $obj->category_rewrite );
				
				if (! isset ( $obj->cover ) || ! $productsImagesArray [$productId] ['id_image']) {
					$obj->cover = $defaultCover;
					$obj->legend = '';
				}
				$productsObj [] = $obj;
			}
		}
		
		$this->smarty->assign ( array (
				'productsObj' => $productsObj,
				'mediumSize' => Image::getSize ( 'medium' ) 
		) );
		
		return $this->display ( __FILE__, 'axxell.tpl' );
	}
	public function hookDisplayRightColumn($params) {
		return $this->renderWidget( $params );
	}
	public function hookDisplayLeftColumn($params) {
		return $this->renderWidget( $params );
	}
	public function hookDisplayFooterProduct($params) {
		return $this->renderWidget( $params );
	}
	public function hookDisplayTop($params) {
		return $this->renderWidget( $params );
	}

	public function hookHeader($params) {
		$id_product = ( int ) Tools::getValue ( 'id_product' );
		
		if ($id_product) {
			$this->registerEvent ( "view", $id_product );
		}
		$this->context->controller->addCSS ( ($this->_path) . 'axxell.css', 'all' );
	}
	public function renderForm() {
		$fields_form = array (
				'form' => array (
						'legend' => array (
								'title' => $this->l ( 'Settings' ),
								'icon' => 'icon-cogs' 
						),
						'input' => array (
								array (
										'type' => 'text',
										'label' => $this->l ( 'API Endpoint URL' ),
										'name' => $this->configEndpointURLLabel,
										'required' => true,
										'class' => 'fixed-width',
										'desc' => $this->l ( 'Full URL to Axxell API' ) 
								),
								array (
										'type' => 'text',
										'label' => $this->l ( 'Access Key' ),
										'name' => $this->configAccessKeyLabel,
										'required' => true,
										'class' => 'fixed-width',
										'desc' => $this->l ( 'Your access key' ) 
								),
								array (
										'type' => 'text',
										'label' => $this->l ( 'Secret Key' ),
										'name' => $this->configSecretKeyLabel,
										'required' => true,
										'class' => 'fixed-width',
										'desc' => $this->l ( 'Your secret key' ) 
								),
								array (
										'type' => 'select',
										'label' => $this->l ( 'Engine Type' ),
										'name' => $this->configEngineTypeLabel,
										'required' => true,
										'class' => 'fixed-width',
										'options' => array (
												'query' => $this->engineTypes,
												'id' => 'name',
												'name' => 'label' 
										),
										'desc' => $this->l ( 'Type of recommendations to show to the consumer' ) 
								),
								array (
										'type' => 'text',
										'label' => $this->l ( 'Products to display' ),
										'name' => $this->configMaxItemsLabel,
										'required' => true,
										'class' => 'fixed-width',
										'desc' => $this->l ( 'Define the number of products displayed in this block.' ) 
								),
								array (
										'type' => 'radio',
										'label' => $this->l ( 'One-time products synchronization' ),
										'name' => $this->configSynchronizeLabel,
										'required' => true,
										'values' => array (
												array (
														'id' => 'active_on',
														'value' => 1,
														'label' => $this->l ( 'Yes' ) 
												),
												array (
														'id' => 'active_off',
														'value' => 0,
														'label' => $this->l ( 'No' ) 
												) 
										),
										'class' => 'fixed-width',
										'desc' => $this->l ( 'Enable this option to push current products catalog to Axxell. Do this if the catalog is out of sync in Axxell.' ) 
								),
								array (
										'type' => 'radio',
										'label' => $this->l ( 'Enabled' ),
										'name' => $this->configEnabledLabel,
										'required' => true,
										'values' => array (
												array (
														'id' => 'enabled_on',
														'value' => 1,
														'label' => $this->l ( 'Yes' ) 
												),
												array (
														'id' => 'enabled_off',
														'value' => 0,
														'label' => $this->l ( 'No' ) 
												) 
										),
										'class' => 'fixed-width',
										'desc' => $this->l ( 'Enable Axxell across your shop or not' ) 
								) 
						),
						'submit' => array (
								'title' => $this->l ( 'Save' ) 
						) 
				) 
		);
		
		$helper = new HelperForm ();
		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$lang = new Language ( ( int ) Configuration::get ( 'PS_LANG_DEFAULT' ) );
		$helper->default_form_language = $lang->id;
		$helper->allow_employee_form_lang = Configuration::get ( 'PS_BO_ALLOW_EMPLOYEE_FORM_LANG' ) ? Configuration::get ( 'PS_BO_ALLOW_EMPLOYEE_FORM_LANG' ) : 0;
		$helper->identifier = $this->identifier;
		$helper->submit_action = 'submitAxxell';
		$helper->currentIndex = $this->context->link->getAdminLink ( 'AdminModules', false ) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
		$helper->token = Tools::getAdminTokenLite ( 'AdminModules' );
		$helper->tpl_vars = array (
				'fields_value' => $this->getConfigFieldsValues (),
				'languages' => $this->context->controller->getLanguages (),
				'id_language' => $this->context->language->id 
		);
		
		return $helper->generateForm ( array (
				$fields_form 
		) );
	}
	public function getConfigFieldsValues() {
		$values = array ();
		foreach ( $this->configLabels as $label ) {
			$value = Tools::getValue ( $label, Configuration::get ( $label ) );
			$values [$label] = $value;
		}
		return $values;
	}
	private function deregisterProduct($product_id) {
		$client = $this->getAxxellClient ();
		$logger = $this->getLogger ();
		$logger->logDebug ( "Deregistering product " . $product_id );
		$accessKey = $this->getAccessKey ();
		
		try {
			$client->deleteItem ( $accessKey, $product_id );
			$logger->logDebug ( 'INFO: Deregistered product ' . $product_id );
		} catch ( \Axxell\ApiException $e ) {
			$logger->logDebug ( 'ERROR: ' . $e->getMessage () );
			$logger->logDebug ( $e->getResponseBody () );
		}
	}
	private function getRecommendations($engineType, $product_id) {
		$client = $this->getAxxellClient ();
		$logger = $this->getLogger();
		$storeid = $this->getAccessKey ();
		$user = $this->getCurrentUser ();
		if ($engineType == null)
			$engineType = $this->getEngineType();
		$count = $this->getItemsCount();
		$productIds = array();
		try {
			if ($engineType == "similar") {
				$logger->logDebug ( "Getting similar recommendations for " . $product_id);
				$items = $client->recommendSimilar($storeid, $user, $product_id, $count);
			} else {
				$logger->logDebug ( "Getting interesting recommendations for " . $user);
				$items = $client->recommendInteresting($storeid, $user, $count);
			}
			foreach ($items as $item) {
				if ($item->getItemId() != $product_id)
					$productIds[] = $item->getItemId();
			}
			$logger->logDebug('Recommending');
			$logger->logDebug($items);
		} catch ( \Axxell\ApiException $e ) {
			$logger->logDebug ( 'ERROR: ' . $e->getMessage () );
			$logger->logDebug ( $e->getResponseBody () );
		}
		return $productIds;
	}
	private function registerEvent($type, $product_id) {
		$client = $this->getAxxellClient ();
		$logger = $this->getLogger ();
		$storeid = $this->getAccessKey ();
		$user = $this->getCurrentUser ();
		$event = new \Axxell\Model\Event ();
		$event->setEventType ( $type );
		$event->setEntityId ( $user );
		$event->setTargetEntityId ( $product_id );
		try {
			$logger->logDebug ( "Registering event " . $event );
			$client->registerEvent ( $storeid, $event );
		} catch ( \Axxell\ApiException $e ) {
			$logger->logDebug ( 'ERROR: ' . $e->getMessage () );
			$logger->logDebug ( $e->getResponseBody () );
		}
	}
	private function registerProduct($product_id) {
		$client = $this->getAxxellClient ();
		$logger = $this->getLogger ();
		$logger->logDebug ( "Registering product " . $product_id );
		$accessKey = $this->getAccessKey ();
		$lang_id = $this->context->language->id;
		$product_info = new Product ( $product_id, false, $lang_id );
		
		$item = new \Axxell\Model\Item ();
		$logger->logDebug ( $product_info->name );
		$item->setTitle ( $product_info->name );
		$item->setItemId ( $product_id );
		$categories = array ();
		foreach ( Product::getProductCategoriesFull ( $product_id, $lang_id ) as $category )
			$categories [] = $category ['name'];
		$item->setCategories ( $categories );
		try {
			$client->registerItem ( $accessKey, $item );
			$logger->logDebug ( 'INFO: Registered new product ' . $item );
		} catch ( \Axxell\ApiException $e ) {
			$logger->logDebug ( 'ERROR: ' . $e->getMessage () );
			$logger->logDebug ( $e->getResponseBody () );
		}
	}
	private function synchronize() {
		$logger = $this->getLogger ();
		$client = $this->getAxxellClient ();
		$lang_id = $this->context->language->id;
		$products = Product::getSimpleProducts ( $lang_id );
		try {
			$client->deleteAllItems ( $this->getAccessKey () );
			$logger->logDebug ( "Removed all items from remote Axxell" );
			foreach ( $products as $product ) {
				$this->registerProduct ( $product ['id_product'] );
			}
		} catch ( \Axxell\ApiException $e ) {
			$logger->logDebug ( 'ERROR: ' . $e->getMessage () );
			$logger->logDebug ( $e->getResponseBody () );
		}
	}
	private function getAxxellClient() {
		$logger = $this->getLogger ();
		if (! $this->isAxxellEnabled ()) {
			$logger->logDebug ( "Axxell module is disabled, turn it on in the configuration screen" );
			return;
		}
		if (! $this->axxellClient) {
			require_once (dirname ( __FILE__ ) . '/vendors/axxell-client-php/autoload.php');
			$config = new \Axxell\Configuration ();
			$api_key = Configuration::get ( $this->configSecretKeyLabel );
			$api_endpoint = Configuration::get ( $this->configEndpointURLLabel );
			$logger->logDebug ( "api endpoint: " . $api_endpoint );
			$config->setHost ( $api_endpoint );
			$config->setApiKey ( 'x-api-key', $api_key );
			$client = new \Axxell\ApiClient ( $config );
			$this->axxellClient = new \Axxell\Api\DefaultApi ( $client );
		}
		return $this->axxellClient;
	}
	private function getLogger() {
		if (! $this->logger) {
			$this->logger = new FileLogger ( 0 );
			$this->logger->setFilename ( _PS_ROOT_DIR_ . "/log/axxell.log" );
		}
		return $this->logger;
	}
	private function getAccessKey() {
		if (! $this->accessKey) {
			$this->accessKey = Configuration::get ( $this->configAccessKeyLabel );
		}
		return $this->accessKey;
	}
	private function isAxxellEnabled() {
		if (! $this->enabled) {
			$this->enabled = Configuration::get ( $this->configEnabledLabel );
		}
		return $this->enabled;
	}
	private function getEngineType() {
		if (! $this->engineType) {
			$this->engineType= Configuration::get ( $this->configEngineTypeLabel );
		}
		return $this->engineType;
	}
	private function getItemsCount() {
		if (! $this->itemsCount) {
			$this->itemsCount= (int)Configuration::get ( $this->configMaxItemsLabel);
		}
		return $this->itemsCount;
	}
	private function getCurrentUser() {
		$context = Context::getContext ();
		if ($context->customer->isLogged ()) {
			$id_customer = $context->customer->id;
		} else {
			$id_customer = 'guest' . $context->cookie->id_guest;
		}
		return $id_customer;
	}
}
