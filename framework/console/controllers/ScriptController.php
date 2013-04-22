<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\console\controllers;

use Yii;
use yii\console\Exception;
use yii\console\Controller;

/**
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class ScriptController extends Controller
{
	public $defaultAction = 'compress';

	public $bundles = array();
	public $extensions = array();
	/**
	 * @var array
	 * ~~~
	 * 'all' => array(
	 *     'css' => 'all.css',
	 *     'js' => 'js.css',
	 *     'depends' => array( ... ),
	 * )
	 * ~~~
	 */
	public $targets = array();
	public $assetManager = array();

	public function actionCompress($configFile, $bundleFile)
	{
		$this->loadConfiguration($configFile);
		$bundles = $this->loadBundles($this->bundles, $this->extensions);
		$targets = $this->loadTargets($this->targets, $bundles);
//		$this->publishBundles($bundles, $this->publishOptions);
		$timestamp = time();
		foreach ($targets as $target) {
			if (!empty($target->js)) {
				$this->buildTarget($target, 'js', $bundles, $timestamp);
			}
			if (!empty($target->css)) {
				$this->buildTarget($target, 'css', $bundles, $timestamp);
			}
		}

		$targets = $this->adjustDependency($targets, $bundles);
		$this->saveTargets($targets, $bundleFile);
	}

	protected function loadConfiguration($configFile)
	{
		foreach (require($configFile) as $name => $value) {
			if (property_exists($this, $name)) {
				$this->$name = $value;
			} else {
				throw new Exception("Unknown configuration option: $name");
			}
		}

		if (!isset($this->assetManager['basePath'])) {
			throw new Exception("Please specify 'basePath' for the 'assetManager' option.");
		}
		if (!isset($this->assetManager['baseUrl'])) {
			throw new Exception("Please specify 'baseUrl' for the 'assetManager' option.");
		}
	}

	protected function loadBundles($bundles, $extensions)
	{
		$result = array();
		foreach ($bundles as $name => $bundle) {
			$bundle['class'] = 'yii\\web\\AssetBundle';
			$result[$name] = Yii::createObject($bundle);
		}
		foreach ($extensions as $path) {
			$manifest = $path . '/assets.php';
			if (!is_file($manifest)) {
				continue;
			}
			foreach (require($manifest) as $name => $bundle) {
				if (!isset($result[$name])) {
					$bundle['class'] = 'yii\\web\\AssetBundle';
					$result[$name] = Yii::createObject($bundle);
				}
			}
		}
		return $result;
	}

	protected function loadTargets($targets, $bundles)
	{
		$registered = array();
		foreach ($bundles as $name => $bundle) {
			$this->registerBundle($bundles, $name, $registered);
		}
		$bundleOrders = array_combine(array_keys($registered), range(0, count($bundles) - 1));
		foreach ($targets as $name => $target) {
			if (!isset($target['basePath'])) {
				throw new Exception("Please specify 'basePath' for the '$name' target.");
			}
			if (!isset($target['baseUrl'])) {
				throw new Exception("Please specify 'baseUrl' for the '$name' target.");
			}
			usort($target['depends'], function ($a, $b) use ($bundleOrders) {
				if ($bundleOrders[$a] == $bundleOrders[$b]) {
					return 0;
				} else {
					return $bundleOrders[$a] > $bundleOrders[$b] ? 1 : -1;
				}
			});
			$target['class'] = 'yii\\web\\AssetBundle';
			$targets[$name] = Yii::createObject($target);
		}
		return $targets;
	}

	/**
	 * @param \yii\web\AssetBundle[] $bundles
	 * @param array $options
	 */
	protected function publishBundles($bundles, $options)
	{
		if (!isset($options['class'])) {
			$options['class'] = 'yii\\web\\AssetManager';
		}
		$am = Yii::createObject($options);
		foreach ($bundles as $bundle) {
			$bundle->publish($am);
		}
	}

	/**
	 * @param \yii\web\AssetBundle $target
	 * @param string $type either "js" or "css"
	 * @param \yii\web\AssetBundle[] $bundles
	 * @param integer $timestamp
	 * @throws Exception
	 */
	protected function buildTarget($target, $type, $bundles, $timestamp)
	{
		$outputFile = strtr($target->$type, array(
			'{ts}' => $timestamp,
		));
		$inputFiles = array();

		foreach ($target->depends as $name) {
			if (isset($bundles[$name])) {
				foreach ($bundles[$name]->$type as $file) {
					$inputFiles[] = $bundles[$name]->basePath . '/' . $file;
				}
			} else {
				throw new Exception("Unknown bundle: $name");
			}
		}
		if ($type === 'js') {
			$this->compressJsFiles($inputFiles, $target->basePath . '/' . $outputFile);
		} else {
			$this->compressCssFiles($inputFiles, $target->basePath . '/' . $outputFile);
		}
		$target->$type = array($outputFile);
	}

	protected function adjustDependency($targets, $bundles)
	{
		$map = array();
		foreach ($targets as $name => $target) {
			foreach ($target->depends as $bundle) {
				if (!isset($map[$bundle])) {
					$map[$bundle] = $name;
				} else {
					throw new Exception("Bundle '$bundle' is found in both target '{$map[$bundle]}' and '$name'.");
				}
			}
		}

		foreach ($targets as $name => $target) {
			$depends = array();
			foreach ($target->depends as $bn) {
				foreach ($bundles[$bn]->depends as $bundle) {
					$depends[$map[$bundle]] = true;
				}
			}
			unset($depends[$name]);
			$target->depends = array_keys($depends);
		}

		// detect possible circular dependencies
		foreach ($targets as $name => $target) {
			$registered = array();
			$this->registerBundle($targets, $name, $registered);
		}

		foreach ($map as $bundle => $target) {
			$targets[$bundle] = Yii::createObject(array(
				'class' => 'yii\\web\\AssetBundle',
				'depends' => array($target),
			));
		}
		return $targets;
	}

	protected function registerBundle($bundles, $name, &$registered)
	{
		if (!isset($registered[$name])) {
			$registered[$name] = false;
			$bundle = $bundles[$name];
			foreach ($bundle->depends as $depend) {
				$this->registerBundle($bundles, $depend, $registered);
			}
			unset($registered[$name]);
			$registered[$name] = true;
		} elseif ($registered[$name] === false) {
			throw new Exception("A circular dependency is detected for target '$name'.");
		}
	}

	protected function saveTargets($targets, $bundleFile)
	{
		$array = array();
		foreach ($targets as $name => $target) {
			foreach (array('js', 'css', 'depends', 'basePath', 'baseUrl') as $prop) {
				if (!empty($target->$prop)) {
					$array[$name][$prop] = $target->$prop;
				}
			}
		}
		$array = var_export($array, true);
		$version = date('Y-m-d H:i:s', time());
		file_put_contents($bundleFile, <<<EOD
<?php
/**
 * Do not modify this file manually as it is automatically generated by the "yiic script" command.
 * @version $version
 */
return $array;
EOD
		);
	}

	protected function compressJsFiles($inputFiles, $outputFile)
	{

	}

	protected function compressCssFiles($inputFiles, $outputFile)
	{

	}
}