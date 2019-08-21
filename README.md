# Skroutz.gr XML Feed

Allows the creation of a valid XML feed for use with the Skroutz.gr service.
Also integrates Skroutz Analytics on required pages.

## Features
* Creates a valid XML file
* Includes product attribute that includes or excludes specific products from the feed
* Option to exclude certain categories from the feed
* Option to include or exclude out of stock products
* Customizable messages for stock availability
* Customizable XML feed output location
* Products without images are automatically excluded
* This module **_IS_** suitable for fashion products

## How to use
#### Step 1
After installing the module, go into the module configuration and set the following:

* XML feed location
* Store name and url.
* Categories to exclude, whether to include out of stock products or not
* Messages for stock availability.

### Step 2
Add the newly created "skroutz" attribute to your attribute sets

### Step 3
Use mass actions to set product attribute "skroutz" to Yes/Or no

### Step 4
Use cron to visit <your-magento-shop>/feed as frequently as you need in order to generate the feed
