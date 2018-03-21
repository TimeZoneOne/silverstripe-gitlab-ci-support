<?php

if (php_sapi_name() != 'cli') {
	header('HTTP/1.0 404 Not Found');
	exit;
}

function load_json($file) {
	return json_decode(file_get_contents($file), true);
}

function save_json($file, $obj) {
	file_put_contents($file, json_encode($obj));
}

class ComposerJSON {

	private $filename;
	private $config;

	public function __construct($file) {
		$this->filename = $file;
		$this->config = load_json($file);
	}

	public function save($path = NULL) {
		file_put_contents($path?:$this->filename, json_encode($this->config, JSON_UNESCAPED_SLASHES));
	}

	public function getValue($key) {
		return array_key_exists($key, $this->config) ? $this->config[$key] : NULL;
	}

	public function setValue($key, $value) {
		if(array_key_exists($key, $this->config)) {
			$this->config[$key] = $value;
		}
	}

	public function removeValue($key)
	{
		if(array_key_exists($key, $this->config)) {
			unset($this->config[$key]);
		};
	}

	public function mergeInto($key, $value) {
		$mergedValue = $this->getValue($key) ?: array();
		if ($value) $mergedValue += $value;
		$this->config[$key] = $mergedValue;
	}

}

class SilverStripeGitlabCiSupport {

	private $moduleFolder;
	private $supportFolder;
	private $ignoreFiles;
	private $project = 'mysite';
	private $dryrun = false;

	public function __construct($moduleFolder, $supportDir) {
		$this->moduleFolder = $moduleFolder;
		$this->supportFolder = basename($supportDir);

		$parent = basename(dirname($supportDir));
		if ( basename(getcwd()) != $parent ) {
			throw new Exception("Must run script from parent directory \"$parent\".");
		}

		$this->ignoreFiles = array('.', '..', '.git', $this->moduleFolder, $this->supportFolder, $this->project);
	}

	public function initialize(){
		$module = $this->getModuleDetails();
		$this->addCurrentModuleToComposer($module);
		$this->moveModuleIntoSubfolder();
		$this->moveProjectIntoRoot();
        $this->run_cmd('cp ' . $this->moduleFolder . '/composer.json .');
        $this->moveToRoot('.env');
        $this->moveToRoot('index.php');
		$this->moveToRoot('phpunit.xml');
		$this->replaceInFile('{{MODULE_DIR}}', $this->getFinalModuleDir($module), './phpunit.xml');
		$this->run_cmd('rm -rf ' . $this->supportFolder);
		$this->run_cmd('rm -rf ' . $this->moduleFolder);
	}

	public function getFinalModuleDir($module)
	{
		return 'vendor/' . $module['name'];
	}

	private function addCurrentModuleToComposer($module)
	{
		$composer = new ComposerJSON('./composer.json');

		$composer->setValue('name', 'test/project');
		$composer->removeValue('extra');
		$composer->removeValue('type');

		$composer->mergeInto('require', [
			$module['name']	=> 'dev-' . $module['version']
		]);
		$composer->mergeInto('repositories', [
			$module['name']	=> [
				'type'		=> 'git',
				'url'		=> $module['vcs'],
				'private'	=> 'true'
			]
		]);
		$composer->save();
	}


	public function getModuleDetails()
	{
		$composer = new ComposerJSON('./composer.json');
		$vcs = $this->run_cmd('git config --get remote.origin.url');

		$this->writeln('Module\'s repo : ' . $vcs);

		if(strpos($vcs, '@') !== false) {
			$vcs = substr($vcs, strpos($vcs, '@') + 1);
			$vcs = 'git@' . preg_replace('/\//', ':', $vcs, 1);
		}

		return [
			'version'	=> $this->getModuleVersion(),
			'detached'	=> $this->run_cmd('git show -s --pretty=%d HEAD'),
			'vcs'		=> $vcs,
			'name'		=> $composer->getValue('name'),
			'type'		=> $composer->getValue('type'),
		];
	}

	public function getModuleVersion()
	{
		$branch = $this->run_cmd('git branch | grep \* | cut -d \' \' -f2');
		if(strpos($branch, '(detached') !== false) {
			$branch = $this->run_cmd('git show -s --pretty=%d HEAD');
			$branch = str_replace('(HEAD, origin/', '', str_replace(')', '', $branch));
		}
		return $branch;
	}

	private function moveProjectIntoRoot() {
		$this->move('./'.$this->supportFolder.'/'.$this->project, './'.$this->project);
	}

	private function moveModuleIntoSubfolder(){
		$moduleFolder = $this->moduleFolder;
		$this->mkdir($moduleFolder);
		foreach(scandir('.') as $file) {
			if (!$this->ignore($file)) {
				$this->move($file, $moduleFolder . '/' . $file);
			}
		}
	}

	private function ignore($file) {
		return in_array($file, $this->ignoreFiles);
	}

	private function moveToRoot($file) {
		$this->move('./'.$this->project.'/'.$file, './'.$file);
	}

	private function replaceInFile($search, $replace, $file) {
		if (!$this->dryrun) {
			$contents = str_replace($search, $replace, file_get_contents($file));
			file_put_contents($file, $contents);
		} else {
			$this->writeln("replace $search -> $replace in $file");
		}
	}

	private function move($from, $to) {
		if (!$this->dryrun) {
			rename($from, $to);
		} else {
			$this->writeln( "mv $from -> $to" );
		}
	}

	private function mkdir($dir) {
		if (!$this->dryrun) {
			mkdir($dir);
		} else {
			$this->writeln( "mkdir $dir" );
		}
	}

	private function run_cmd($cmd) {
		if (!$this->dryrun) {
			ob_start();
			passthru($cmd, $returnVar);
			$result = ob_get_contents();
			ob_end_clean();
			if($returnVar > 0) die($returnVar);
			return trim($result);
		}

		$this->writeln( "+ $cmd" );
	}

	private function writeln($str = '') {
		echo $str . "\n";
	}
}

$support = new SilverStripeGitlabCiSupport('module-under-test', __DIR__);
$support->initialize();


