<?php

class ID_Feed_IndexController extends Mage_Core_Controller_Front_Action {

  private $oProducts;
  private $oProdudctIds;
  private $oProductModel;
  private $store_name;
  private $xml_file_name;
  private $xml_path;
  private $file;
  private $excluded;
  private $xml;
  private $xmlContents;

  private $attribute;

  private $BadChars = array('"',"\r\n","\n","\r","\t");
  private $ReplaceChars = array(""," "," "," ","");

  private $notAllowed = array('Νο', 'Όχι');

  private function init()
  {
    $this->store_name = Mage::getStoreConfig('feed/feed/store_name');
    $this->xml_file_name = Mage::getStoreConfig('feed/feed/xml_file_name');
    $this->xml_path = Mage::getStoreConfig('feed/feed/feed_path');
    $this->file = $this->xml_path . $this->xml_file_name;

    $this->attribute = Mage::getStoreConfig('feed/feed/attribute');

    $this->show_outofstock = Mage::getStoreConfig('feed/collection/show_unavailable');
    $this->excluded = explode(',', Mage::getStoreConfig('feed/collection/excluded_cats'));

    $this->instock_msg = Mage::getStoreConfig('feed/messages/in_stock');
    $this->nostock_msg = Mage::getStoreConfig('feed/messages/out_of_stock');
    $this->backorder_msg = Mage::getStoreConfig('feed/messages/backorder');
  }

  public function indexAction() {

    $this->init();

    $this->getProducts();
    $this->createXML();

    $this->openXML();

    $base_node = $this->xml->getElementsByTagName('products')->item(0);

    foreach ($this->oProdudctIds as $iProduct) {
      @set_time_limit(0);

      $oProduct = Mage::getModel('catalog/product');
      $oProduct ->load($iProduct);
      $stockItem = $oProduct->isAvailable();
      $skroutz = $oProduct->getData( $this->attribute );
      if($stockItem == 1 && $skroutz == 1) {
        $p = $this->getProductData($iProduct);

        $product = $this->xml->createElement("product");
        $base_node->appendChild( $product );

        $product->appendChild ( $this->xml->createElement('id', $p['id']) );
        $product->appendChild ( $this->xml->createElement('mpn', $p['mpn']) );
        $product->appendChild ( $this->xml->createElement('manufacturer', $p['brand']) );

        $name = $product->appendChild($this->xml->createElement('name'));
        $name->appendChild($this->xml->createCDATASection( $p['title'] ));

        $description = $product->appendChild($this->xml->createElement('description'));
        $description->appendChild($this->xml->createCDATASection( $p['description'] ));

        $product->appendChild ( $this->xml->createElement('price', $p['price']) );
        $product->appendChild ( $this->xml->createElement('link', $p['link']) );
        $product->appendChild ( $this->xml->createElement('image', $p['image_link_large']) );
        $product->appendChild ( $this->xml->createElement('InStock', $p['stock']) );
        $product->appendChild ( $this->xml->createElement('Availability', $p['stock_descrip']) );

        $category = $product->appendChild($this->xml->createElement('category'));
        $category->appendChild($this->xml->createCDATASection( $p['category'] ));

        $product->appendChild ( $this->xml->createElement('categoryid', $p['categoryid']) );

        if( $p['color'] != '' && !in_array($p['color'], $this->notAllowed) ) {
          $product->appendChild ( $this->xml->createElement('color', $p['color']) );
        }

        if( $p['size'] != '' ) {
          $product->appendChild ( $this->xml->createElement('size', $p['size']) );
        }

        $this->xml->formatOutput = true;
        $this->xml->save($this->file);

      } // endif

    } // endforeach

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

  private function sanitize($data) {
    $sanitized = array();
    foreach($data as $k=>$val){
      $sanitized[$k] = str_replace($this->BadChars,$this->ReplaceChars,$val);
    }
    return $sanitized;
  }

  private function getProducts() {
    $this->oProducts = Mage::getModel('catalog/product')->getCollection();
    $this->oProducts->addAttributeToFilter('status', 1); //enabled
    $this->oProducts->addAttributeToFilter('visibility', 4); //catalog, search
    $this->oProducts->addAttributeToFilter(
      array(
        array('attribute'=> $this->attribute, 'eq' => '1'),
      )
    ); //skroutz products only
    $this->oProducts->addAttributeToSelect('*');
    if( $this->show_outofstock ) {
      $this->oProducts->joinField('qty',
                   'cataloginventory/stock_item',
                   'qty',
                   'product_id=entity_id',
                   '{{table}}.stock_id=1',
                   'left');
      $this->oProducts->addAttributeToFilter('qty', array("gt" > 0));
    }
    $this->oProdudctIds = $this->oProducts->getAllIds();
  }

  private function getProductData($iProduct) {
    $oProduct = Mage::getModel('catalog/product');
    $oProduct ->load($iProduct);

    $aCats = $this->getCategories($oProduct);

    $aData = array();

    $aData['id']=$iProduct;
    $aData['mpn']=mb_substr($oProduct->getSku(),0,99,'UTF-8');

    $aData['brand']=@mb_substr($oProduct->getResource()->getAttribute('manufacturer')->getFrontend()->getValue($oProduct),0,99,'UTF-8');

    //if(isset($aData['brand']) && $aData['brand']!=''){
    $_finalPrice = $oProduct->getFinalPrice();
    $product_price = Mage::helper('tax')->getPrice($oProduct, $_finalPrice, true);
    if( $product_price >= 60 && $product_price < 200 ) {
      $aData['title']= $aData['brand'] . ' ' . mb_substr($oProduct->getName(),0,299,'UTF-8') . ' (Πληρωμή με 2 άτοκες δόσεις)';
    } elseif( $product_price >= 200 ) {
      $aData['title']= $aData['brand'] . ' ' . mb_substr($oProduct->getName(),0,299,'UTF-8') . ' (Πληρωμή με 4 άτοκες δόσεις)';
    } else {
      $aData['title']= $aData['brand'] . ' ' . mb_substr($oProduct->getName(),0,299,'UTF-8');
    }
    //$aData['title']=mb_substr($oProduct->getName(),0,299,'UTF-8');

    $aData['description']= strip_tags($oProduct->getShortDescription());
    $aData['price'] = preg_replace('/,/', '.', Mage::helper('tax')->getPrice($oProduct, $_finalPrice, true));

    $aData['link']=mb_substr($oProduct->getProductUrl(),0,299,'UTF-8');
    $aData['image_link_large']= mb_substr(Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA).'catalog/product'.$oProduct->getImage(),0,399,'UTF-8');

    $inventory =  Mage::getModel('cataloginventory/stock_item')->loadByProduct($oProduct);

    if( $oProduct->isAvailable() && $inventory->getBackorders() == 0 ) {
      $aData['stock']='Y';
      $aData['stock_descrip'] = $this->instock_msg;
    } elseif( $oProduct->isAvailable() && $inventory->getBackorders() != 0 ) {
      $aData['stock']='Y';
      $aData['stock_descrip'] = $this->backorder_msg;
    } elseif( !$oProduct->isAvailable() ) {
      $aData['stock']='Y';
      $aData['stock_descrip'] = $this->nostock_msg;
    }

    $aData['categoryid'] = $aCats['cid'];
    $aData['category'] = $aCats['bread'];

    $aData['color']=@mb_substr($oProduct->getResource()->getAttribute('color')->getFrontend()->getValue($oProduct),0,99,'UTF-8');

    if( $oProduct->isConfigurable() ) {
        unset($sizes);
        $parent = Mage::getModel('catalog/product_type_configurable')->setProduct($oProduct);
        $child = $parent->getUsedProductCollection()->addAttributeToSelect('*')->addFilterByRequiredOptions();
        foreach($child as $simple_product) {
          if( !in_array($simple_product->getResource()->getAttribute('size')->getFrontend()->getValue($simple_product), $this->notAllowed) )
            $sizes[] = $simple_product->getResource()->getAttribute('size')->getFrontend()->getValue($simple_product);
        }
        if( count($sizes) > 0 ) {
          $aData['size'] = implode(',', $sizes);
        } else {
          $aData['size'] = '';
        }
    }
    return $aData;
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