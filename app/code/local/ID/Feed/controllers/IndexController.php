<?php

class ID_Feed_IndexController extends Mage_Core_Controller_Front_Action {

	private $oProducts;
	private $oProdudctIds;
	private $store_name;
	private $xml_file_name;
	private $xml_path;
	private $file;
	private $excluded;
	private $xml;
	private $xmlContents;
	private $base_node;

	private $BadChars = array('"',"\r\n","\n","\r","\t");
	private $ReplaceChars = array(""," "," "," ","");

	private $notAllowed = array('Νο', 'Όχι');

	private function init()
	{
		$this->store_name = Mage::getStoreConfig('feed/feed/store_name');
		$this->xml_file_name = Mage::getStoreConfig('feed/feed/xml_file_name');
		$this->xml_path = Mage::getStoreConfig('feed/feed/feed_path');
		$this->file = $this->xml_path . $this->xml_file_name;

		$this->show_outofstock = Mage::getStoreConfig('feed/collection/show_unavailable');
		$this->excluded = explode(',', Mage::getStoreConfig('feed/collection/excluded_cats'));

		$this->instock_msg = Mage::getStoreConfig('feed/messages/in_stock');
		$this->nostock_msg = Mage::getStoreConfig('feed/messages/out_of_stock');
		$this->backorder_msg = Mage::getStoreConfig('feed/messages/backorder');

		$this->base_url = Mage::app()->getStore(1)->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);
		$this->media_url = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA) . 'catalog/product';
	}

	public function indexAction() {

		$time_start = microtime(true);

		$this->init();
		$this->createXML();
		$this->openXML();

		$this->base_node = $this->xml->getElementsByTagName('products')->item(0);

		$this->getProducts();

		$this->xml->formatOutput = true;
		$this->xml->save($this->file);

		echo 'XML Feed generated in: ' . number_format((microtime(true) - $time_start), 2) . ' seconds';

	}

	private function createXML() {
		$dom = new DomDocument("1.0", "utf-8");
		$dom->formatOutput = true;

		$root = $dom->createElement($this->store_name);

		$stamp = $dom->createElement('created_at', date('Y-m-d H:i') );
		$root->appendChild($stamp);

		$nodes = $dom->createElement('products');
		$root->appendChild($nodes);

		$nameAttribute = $dom->createAttribute('name');
		$nameAttribute->value = Mage::app()->getStore()->getFrontendName();
		$root->appendChild($nameAttribute);

		$urlAttribute = $dom->createAttribute('url');
		$urlAttribute->value = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
		$root->appendChild($urlAttribute);

		$dom->appendChild($root);

		$dom->save($this->file);
	}

	private function openXML() {
		$this->xml = new DOMDocument();
		$this->xml->formatOutput = true;
		$this->xml->load($this->file);
	}

	private function getProducts() {
		$this->oProducts = Mage::getModel('catalog/product')->getCollection();
		$this->oProducts->addAttributeToFilter('status', 1); //enabled
		$this->oProducts->addAttributeToFilter('visibility', 4); //catalog, search
		$this->oProducts->addAttributeToFilter(
			array(
				array('attribute'=>'skroutz', 'eq' => '1'),
			)
		); //skroutz products only
		$this->oProducts->addAttributeToSelect(['entity_id', 'skroutz','sku','name','manufacturer_value','final_price','short_description','url_path','small_image','color_value','type_id', 'image']);
		if( !$this->show_outofstock ) {
			$this->oProducts->joinField('qty',
					'cataloginventory/stock_item',
					'qty',
					'product_id=entity_id',
					'{{table}}.stock_id=1',
					'left'
				);
			$this->oProducts->joinTable('cataloginventory/stock_item', 'product_id=entity_id', array('stock_status' => 'is_in_stock'));
			$this->oProducts->addAttributeToFilter('stock_status', 1);
			// alt
			//$this->oProducts->joinTable('cataloginventory/stock_item', 'product_id=entity_id', array('*'), 'is_in_stock = 1');
		}
		$this->oProducts->addFinalPrice();

		Mage::getSingleton('core/resource_iterator')->walk(
			$this->oProducts->getSelect(),
			array(array($this, 'productCallback')),
			array('store_id' => $storeId)
		);
	}

	public function productCallback($args) {
		$oProduct = Mage::getModel('catalog/product')->setData($args['row']);

		$aCats = $this->getCategories($oProduct);

		$aData = array();

		$aData['id'] = $oProduct->entity_id;
		$aData['mpn'] = mb_substr($oProduct->sku,0,99,'UTF-8');

		$aData['brand'] = strtoupper( @mb_substr($oProduct->manufacturer_value,0,99,'UTF-8') );

		$_finalPrice = $oProduct->final_price;
		$aData['title'] = @mb_substr($oProduct->name,0,299,'UTF-8');

		$aData['description']= strip_tags($oProduct->short_description);
		$aData['price'] = preg_replace('/,/', '.', Mage::helper('tax')->getPrice($oProduct, $_finalPrice, true));

		$aData['link'] = mb_substr($this->base_url . $oProduct->url_path,0,299,'UTF-8');
		$aData['image_link_large'] = mb_substr($this->media_url.$oProduct->small_image,0,399,'UTF-8');
		
		$attributes = $oProduct->getTypeInstance(true)->getSetAttributes($oProduct);
    	$media_gallery = $attributes['media_gallery'];
    	$backend = $media_gallery->getBackend();
    	$backend->afterLoad($oProduct);
    	$mediaGallery = $oProduct->getMediaGalleryImages();

	  	foreach($oProduct->getMediaGalleryImages() as $image) {
	    	if( $image->getPosition() != 1 ) {
        		$aData['additional_imageurl'][] = $image->getUrl();
      		}
	  	}

		if( $this->show_outofstock ) {
			if( $oProduct->isAvailable() ) {
				$aData['stock'] = 'Y';
				$aData['stock_descrip'] = $this->instock_msg;
			} else {
				$aData['stock'] = 'N';
				$aData['stock_descrip'] = $this->nostock_msg;
			}
		} else {
			$aData['stock'] = 'Y';
			$aData['stock_descrip'] = $this->instock_msg;
		}

		$aData['categoryid'] = array_key_exists('cid', $aCats) ? $aCats['cid'] : '';
		$aData['category'] = array_key_exists('bread', $aCats) ? $aCats['bread'] : '';

		$aData['color'] = @mb_substr($oProduct->color_value,0,99,'UTF-8');

		if( $oProduct->type_id == 'configurable' ) {
				unset($sizes);
				$parent = Mage::getModel('catalog/product_type_configurable')->setProduct($oProduct);
				$child = $parent->getUsedProductCollection()->addAttributeToSelect('*');
				if( !$this->show_outofstock ) {
      				$child->joinTable('cataloginventory/stock_item', 'product_id=entity_id', array('*'), 'is_in_stock = 1');
    			}
				
				foreach($child as $simple_product) {
					if( !in_array($simple_product->getAttributeText('size'), $this->notAllowed) ) {
						$sizes[] = $simple_product->getAttributeText('size');
					}
				}
				if( $sizes && count($sizes) > 0 ) {
					$aData['size'] = implode(',', $sizes);
				} else {
					$aData['size'] = '';
				}
		}
		$this->appendXML($aData);
	}

	private function appendXML($p) {
		if( substr( $p['image_link_large'], -4 ) == '.jpg' ) {

			$product = $this->xml->createElement("product");
			$this->base_node->appendChild( $product );

			$product->appendChild ( $this->xml->createElement('id', $p['id']) );
			$product->appendChild ( $this->xml->createElement('mpn', $p['mpn']) );
			$product->appendChild ( $this->xml->createElement('manufacturer', htmlspecialchars($p['brand'])) );
			$product->appendChild ( $this->xml->createElement('name', htmlspecialchars($p['title'])) );

			$description = $product->appendChild($this->xml->createElement('description'));
			$description->appendChild($this->xml->createCDATASection( $p['description'] ));

			$product->appendChild ( $this->xml->createElement('price', $p['price']) );
			$product->appendChild ( $this->xml->createElement('link', $p['link']) );
			$product->appendChild ( $this->xml->createElement('image', $p['image_link_large']) );
			
			if( $p['additional_imageurl'] ) {
				foreach($p['additional_imageurl'] as $image) {
					$product->appendChild ( $this->xml->createElement('additional_imageurl', $image) );
				}
			}
			
			$product->appendChild ( $this->xml->createElement('InStock', $p['stock']) );
			$product->appendChild ( $this->xml->createElement('Availability', $p['stock_descrip']) );

			$category = $product->appendChild($this->xml->createElement('category'));
			$category->appendChild($this->xml->createCDATASection( $p['category'] ));

			$product->appendChild ( $this->xml->createElement('categoryid', $p['categoryid']) );

			if( $p['color'] != '' && !in_array($p['color'], $this->notAllowed) ) {
				$product->appendChild ( $this->xml->createElement('color', $p['color']) );
			}

			if( array_key_exists('size', $p) && $p['size'] != '' ) {
				$product->appendChild ( $this->xml->createElement('size', $p['size']) );
			}

		}
	}

	private function getCategories($oProduct) {
		$aIds = $oProduct->getCategoryIds();
		$aCategories = array();
		$catPath = array();
		$aCategories['bread'] = '';

		foreach($aIds as $iCategory){
			if (!in_array($iCategory, $this->excluded)) {
			$aCategories['bread'] = '';
				$oCategory = Mage::getModel('catalog/category')->load($iCategory);
				$aCategories['cid'] = $oCategory->getId();
				$aCategories['catpath'] = $oCategory->getPath();
				$catPath = explode('/', $aCategories['catpath']);
				foreach($catPath as $cpath){
					$pCategory = Mage::getModel('catalog/category')->load($cpath);
					if($pCategory->getName() !='Root Catalog' && $pCategory->getName()!='Default Category'&& $pCategory->getName()!='ΚΑΤΗΓΟΡΙΕΣ'&& $pCategory->getName()!=''){
						if (!in_array($pCategory->getId(), $this->excluded)) {
							$aCategories['bread'] .= $pCategory->getName() . ' > ';
						}
					}
				}
				$aCategories['bread'] = mb_substr(trim(substr($aCategories['bread'],0,-3)),0,299,'UTF-8');
			}
		}

		return $aCategories;
	}

}