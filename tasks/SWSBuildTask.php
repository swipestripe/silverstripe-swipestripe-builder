<?php

class SWSBuildTask extends BuildTask {
	
	protected $title = "SwipeStripe Build";
	
	protected $description = "Create the shop with data";

	protected static $fixture_file = 'swipestripe-builder/tasks/SWS.yml';

	protected $fixtureFactory;
	
	public function run($request) {

		$dbAdmin = DatabaseAdmin::create();
		increase_time_limit_to(600);
		SS_ClassLoader::instance()->getManifest()->regenerate();

		$dbAdmin->clearAllData();
		$dbAdmin->doBuild(true);

		// Build again for good measure
		$dbAdmin->doBuild(true, false);

		//Move images to assets/Uploads/
		$assetsDir = Director::baseFolder() . '/assets/Uploads';
		$imagesDir = Director::baseFolder() . '/swipestripe-builder/images';

		foreach (new DirectoryIterator($assetsDir) as $fileInfo){
			if(!$fileInfo->isDot()) {
				@unlink($fileInfo->getPathname());
			}
		}
		
		Filesystem::sync();

		foreach (new DirectoryIterator($imagesDir) as $fileInfo){
			if($fileInfo->isFile()) {
				copy($fileInfo->getPathname(), $assetsDir . '/' . $fileInfo->getFilename());
			}
		}
		
		//Build DB
		$fixture = Injector::inst()->create('YamlFixture', self::$fixture_file);
		$fixture->writeInto($this->getFixtureFactory());

		//Update the shop config
		$config = ShopConfig::current_shop_config();
		$config->BaseCurrency = 'NZD';
		$config->BaseCurrencySymbol = '$';
		$config->EmailSignature = '';
		$config->ReceiptSubject = 'Your order details from SwipeStripe demo site';
		$config->ReceiptBody = '';
		$config->ReceiptFrom = 'info@swipestripe.com';
		$config->NotificationSubject = 'New order on SwipeStripe demo site';
		$config->NotificationBody = '';
		$config->NotificationTo = 'info@swipestripe.com';
		$config->write();

		$this->createProductImages();

		// Populate flat fee shipping rates
		$this->createShippingRates();

		// Populate tax rates
		$this->createTaxRates();

		// Populate exchange rates
		$this->createExchangeRates();

		// Populate coupon codes
		$this->createCouponCodes();
		
		// Product categories
		$this->createProductCategories();

		// Product images
		$this->createProductImages();

		// Clear product meta
		$products = Product::get();
		if ($products && $products->exists()) foreach ($products as $product) {
			$product->ExtraMeta = '';
			$product->doPublish();
		}

		// Create home page
		if (class_exists('HomePage')) {
			$page = Page::get()
				->where("\"URLSegment\" = 'home'")
				->first();

			$page->ClassName = 'HomePage';
			$page->doPublish();
		}
	}

	private function createShippingRates() {
		if (class_exists('FlatFeeShippingRate')) {
			$fixture = Injector::inst()->create('YamlFixture', 'swipestripe-builder/tasks/SWSFlatFeeShipping.yml');
			$fixture->writeInto($this->getFixtureFactory());
		}
	}

	private function createTaxRates() {
		if (class_exists('FlatFeeTaxRate')) {
			$fixture = Injector::inst()->create('YamlFixture', 'swipestripe-builder/tasks/SWSFlatFeeTax.yml');
			$fixture->writeInto($this->getFixtureFactory());
		}
	}

	private function createExchangeRates() {
		if (class_exists('ExchangeRate')) {
			$fixture = Injector::inst()->create('YamlFixture', 'swipestripe-builder/tasks/SWSCurrency.yml');
			$fixture->writeInto($this->getFixtureFactory());
		}
	}

	private function createCouponCodes() {
		if (class_exists('Coupon')) {
			$fixture = Injector::inst()->create('YamlFixture', 'swipestripe-builder/tasks/SWSCoupon.yml');
			$fixture->writeInto($this->getFixtureFactory());
		}
	}

	private function createProductCategories() {
		if (class_exists('ProductCategory')) {

			$fixture = Injector::inst()->create('YamlFixture', 'swipestripe-builder/tasks/SWSProductCategory.yml');
			$fixture->writeInto($this->getFixtureFactory());

			$cats = ProductCategory::get();
			if ($cats && $cats->exists()) foreach ($cats as $cat) {
				$cat->doPublish();
			}

			$products = Product::get();
			if ($products && $products->exists()) foreach ($products as $product) {

				$extra = json_decode($product->ExtraMeta);
				if (is_object($extra)) { 

					//Categories
					if ($segment = $extra->ProductCategory) {

						$cat = ProductCategory::get()
							->where("\"URLSegment\" = '$segment'")
							->first();

						if ($cat && $cat->exists()) {

							$product->ParentID = $cat->ID;

							$relation = new ProductCategory_Products();
							$relation->ProductCategoryID = $cat->ID;
							$relation->ProductID = $product->ID;
							$relation->write();
						}
					}
				}
				$product->doPublish();
			}
		}
	}

	private function createProductImages() {
		if (class_exists('Product_Images')) {

			$products = Product::get();
			if ($products && $products->exists()) foreach ($products as $product) {

				$extra = json_decode($product->ExtraMeta);
				if (is_object($extra)) { 

					//Categories
					if (isset($extra->Image) && $segment = $extra->Image) {

						$image = Image::get()
							->where("\"Filename\" = '$segment'")
							->first();

						if ($image && $image->exists()) {

							$relation = $product->getManyManyComponents('Images');
							$relation->add($image);
						}
					}
				}
			}
		}
	}

	/**
	 * @return FixtureFactory
	 */
	public function getFixtureFactory() {
		if(!$this->fixtureFactory) $this->fixtureFactory = Injector::inst()->create('FixtureFactory');
		return $this->fixtureFactory;
	}
}
