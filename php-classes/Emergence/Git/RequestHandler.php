<?php	
namespace Emergence\Git;

use Site,Exception,Git,Benchmark;

class RequestHandler extends \RequestHandler
{
    public static $userResponseModes = [
        'application/json' => 'json'
	];

	protected static function getRequestedLayer()
	{
		// get repo
		if (empty($_REQUEST['layer'])) {
		    throw new Exception('Parameter "layer" required');
		}
		
		$layerId = $_REQUEST['layer'];
		
		if(!$layer = Layer::getById($layerId)) {
			throw new Exception('Requested layer "' . $layerId . '" is not defined');
		}
		
		return $layer;
	}

    public static function handleRequest()
    {
        $GLOBALS['Session']->requireAccountLevel('Developer');
        
        switch($action ? $action : $action = static::shiftPath())
    	{
            case 'commit':
                return static::handleCommitRequest();
            case 'export':
                return static::handleExportRequest();
            case 'import':
                return static::handleImportRequest();
            case 'init':
                return static::handleInitRequest();
            case 'pull':
                return static::handlePullRequest();
            case 'push':
                return static::handlePushRequest();
            case 'status':
                return static::handleStatusRequest();
    	}
        
        return static::throwNotFoundError();
    }
    
    public static function handleCommitRequest()
    {
        // get repo
        if (empty($_REQUEST['repo'])) {
            die('Parameter "repo" required');
        }
        
        $repoName = $_REQUEST['repo'];
        
        if (!array_key_exists($repoName, Git::$repositories)) {
            die("Repo '$repoName' is not defined in Git::\$repositories");
        }
        
        $repoCfg = Git::$repositories[$repoName];
        
        $exportOptions = array(
            'localOnly' => false
        );
        
        if (!empty($repoCfg['localOnly'])) {
            $exportOptions['localOnly'] = true;
        }
        
        // get message
        if (empty($_POST['message'])) {
            die(
                '<form method="POST">'
                    .'<label>Commit message: <input type="text" name="message" size="50"></label>'
                    .'<input type="submit" value="Commit">'
                .'</form>'
            );
        }
        
        
        // start the process
        set_time_limit(0);
        Benchmark::startLive();
        Benchmark::mark("configured request: repoName=$repoName");
        
        
        // get paths
        $repoPath = "$_SERVER[SITE_ROOT]/site-data/git/$repoName";
        $keyPath = "$repoPath.key";
        $gitWrapperPath = "$repoPath.git.sh";
        putenv("GIT_SSH=$gitWrapperPath");
        
        
        // check if there is an existing repo
        if (!is_dir("$repoPath/.git")) {
            die("$repoPath does not contain .git");
        }
        
        
        // get repo
        chdir($repoPath);
        $repo = new PHPGit_Repository($repoPath, !empty($_REQUEST['debug']));
        Benchmark::mark("loaded git repo in $repoPath");
        
        
        // verify repo state
        if ($repo->getCurrentBranch() != $repoCfg['workingBranch']) {
            die("Current branch in $repoPath is not $repoCfg[workingBranch]; aborting.");
        }
        Benchmark::mark("verified working branch");
        
        // sync trees
        foreach ($repoCfg['trees'] AS $srcPath => $treeOptions) {
            if (is_string($treeOptions)) {
                $treeOptions = array(
                    'path' => $treeOptions
                );
            }
        
            $treeOptions = array_merge($exportOptions, $treeOptions);
        
            if (!is_string($srcPath)) {
                $srcPath = $treeOptions['path'];
            } elseif (!$treeOptions['path']) {
                $treeOptions['path'] = $srcPath;
            }
        
            $srcFileNode = Site::resolvePath($srcPath);
        
            if (is_a($srcFileNode, 'SiteFile')) {
                $destDir = dirname($treeOptions['path']);
        
                if ($destDir && !is_dir($destDir)) {
                    mkdir($destDir, 0777, true);
                }
        
                copy($srcFileNode->RealPath, $treeOptions['path']);
                Benchmark::mark("exported file $srcPath to $treeOptions[path]");
            } else {
                $exportResult = Emergence_FS::exportTree($srcPath, $treeOptions['path'], $treeOptions);
                Benchmark::mark("exported directory $srcPath to $treeOptions[path]: ".http_build_query($exportResult));
            }
        }
        
        
        if (!empty($_REQUEST['syncOnly'])) {
            exit();
        }
        
        // set author
        $repo->git(sprintf('git config user.name "%s"', $GLOBALS['Session']->Person->FullName));
        $repo->git(sprintf('git config user.email "%s"', $GLOBALS['Session']->Person->Email));
        
        // commit changes
        $repo->git('add --all');
        
        $repo->git(sprintf(
            'commit -n -m "%s"'
            ,addslashes($_POST['message'])
        ));
        Benchmark::mark("committed all changes");
        
        
        // push changes
        $repo->git("push origin $repoCfg[workingBranch]");
        Benchmark::mark("pushed to $repoCfg[workingBranch]");
    }

	public static function handleStatusRequest()
	{
		$GLOBALS['Session']->requireAccountLevel('Developer');

		$layers = [];

		foreach (Layer::getAll() AS $layer) {
			$layerData = [
				'id' => $layer->getId(),
				'config' => $layer->getConfig(),
				'initialized'  => $layer->isRepositoryInitialized()
			];

			if ($layerData['initialized']) {
				$git = $layer->getGitWrapper();

				// fetch every branch
				$git->run('fetch', ['--all']);

				$layerData['status'] = $git->run('status', ['-sb']); // force color ansi with -c color.status=always
				$layerData['workingBranch'] = $layer->getWorkingBranch();
				$layerData['upstreamBranch'] = $layer->getUpstreamBranch();
			}

			$layers[] = $layerData;
		}

		return static::respond('layers', [
			'layers' => $layers
		]);
	}
    
    public static function handleImportRequest()
    {
        // get repo
        if (empty($_REQUEST['repo'])) {
            die('Parameter "repo" required');
        }
        
        $repoName = $_REQUEST['repo'];
        
        if (!array_key_exists($repoName, Git::$repositories)) {
            die("Repo '$repoName' is not defined in Git::\$repositories");
        }
        
        $repoCfg = Git::$repositories[$repoName];
        
        
        // start the process
        set_time_limit(0);
        Benchmark::startLive();
        Benchmark::mark("configured request: repoName=$repoName");
        
        
        // get paths
        $repoPath = "$_SERVER[SITE_ROOT]/site-data/git/$repoName";
        
        // check if there is an existing repo
        if (!is_dir("$repoPath/.git")) {
            die("$repoPath does not contain .git");
        }
        
        // get repo
        chdir($repoPath);
        
        // sync trees
        foreach ($repoCfg['trees'] AS $srcPath => $treeOptions) {
            if (is_string($treeOptions)) {
                $treeOptions = array(
                    'path' => $treeOptions
                );
            }
        
            if (!is_string($srcPath)) {
                $srcPath = $treeOptions['path'];
            } elseif (!$treeOptions['path']) {
                $treeOptions['path'] = $srcPath;
            }
        
            if (is_string($treeOptions['exclude'])) {
                $treeOptions['exclude'] = array($treeOptions['exclude']);
            }
        
            $treeOptions['exclude'][] = '#(^|/)\\.git(/|$)#';
            $treeOptions['dataPath'] = false;
        
            try {
                if (is_file($treeOptions['path'])) {
                    $sha1 = sha1_file($treeOptions['path']);
                    $existingNode = Site::resolvePath($srcPath);
        
                    if (!$existingNode || $existingNode->SHA1 != $sha1) {
                        $fileRecord = SiteFile::createFromPath($srcPath, null, $existingNode ? $existingNode->ID : null);
                        SiteFile::saveRecordData($fileRecord, fopen($treeOptions['path'], 'r'), $sha1);
                        Benchmark::mark("importing file $srcPath from $treeOptions[path]");
                    } else {
                        Benchmark::mark("skipped unchanged file $srcPath from $treeOptions[path]");
                    }
                } else {
                    $exportResult = Emergence_FS::importTree($treeOptions['path'], $srcPath, $treeOptions);
                    Benchmark::mark("importing directory $srcPath from $treeOptions[path]: ".http_build_query($exportResult));
                }
            } catch (Exception $e) {
                Benchmark::mark("failed to import directory $srcPath from $treeOptions[path]: ".$e->getMessage());
            }
        }
    }
    
    public static function handleExportRequest()
    {

        // get repo
        if (empty($_REQUEST['repo'])) {
            die('Parameter "repo" required');
        }
        
        $repoName = $_REQUEST['repo'];
        
        if (!array_key_exists($repoName, Git::$repositories)) {
            die("Repo '$repoName' is not defined in Git::\$repositories");
        }
        
        $repoCfg = Git::$repositories[$repoName];
        
        $exportOptions = array(
            'localOnly' => false
        );
        
        if (!empty($repoCfg['localOnly'])) {
            $exportOptions['localOnly'] = true;
        }
        
        // start the process
        set_time_limit(0);
        Benchmark::startLive();
        Benchmark::mark("configured request: repoName=$repoName");
        
        
        // get paths
        $repoPath = "$_SERVER[SITE_ROOT]/site-data/git/$repoName";
        
        // check if there is an existing repo
        if (!is_dir("$repoPath/.git")) {
            die("$repoPath does not contain .git");
        }
        
        
        // get repo
        chdir($repoPath);
        
        // sync trees
        foreach ($repoCfg['trees'] AS $srcPath => $treeOptions) {
            if (is_string($treeOptions)) {
                $treeOptions = array(
                    'path' => $treeOptions
                );
            }
        
            $treeOptions = array_merge($exportOptions, $treeOptions, ['dataPath' => false]);
        
            if (!is_string($srcPath)) {
                $srcPath = $treeOptions['path'];
            } elseif (!$treeOptions['path']) {
                $treeOptions['path'] = $srcPath;
            }
        
            $srcFileNode = Site::resolvePath($srcPath);
        
            if (is_a($srcFileNode, 'SiteFile')) {
                $destDir = dirname($treeOptions['path']);
        
                if ($destDir && !is_dir($destDir)) {
                    mkdir($destDir, 0777, true);
                }
        
                copy($srcFileNode->RealPath, $treeOptions['path']);
                Benchmark::mark("exported file $srcPath to $treeOptions[path]");
            } else {
                $exportResult = Emergence_FS::exportTree($srcPath, $treeOptions['path'], $treeOptions);
                Benchmark::mark("exported directory $srcPath to $treeOptions[path]: ".http_build_query($exportResult));
            }
        }
        
        Benchmark::mark("wrote all changes");
    }

	public static function handleInitRequest()
	{
		$GLOBALS['Session']->requireAccountLevel('Developer');

		try {
			$layer = static::getRequestedLayer();
		} catch (Exception $e) {
			return static::throwInvalidRequestError($e->getMessage());
		}

		if ($_SERVER['REQUEST_METHOD'] != 'POST') {
			return static::respond('configureInit', [
				'layer' => $layer
			]);
		}

		return static::respond('repositoryInitialized', [
			'layer' => $layer,
			'results' => $layer->initializeRepository($_POST['privateKey'])
		]);
	}

	public static function handleSyncToDiskRequest()
	{
		$GLOBALS['Session']->requireAccountLevel('Developer');

		if ($_SERVER['REQUEST_METHOD'] != 'POST') {
			return static::throwInvalidRequestError('Request must be POST');
		}

        // Disable diagnostics by default for this operation as a high volume of queries may be needed
        Site::$debug = !empty($_GET['debug']);

		try {
			$layer = static::getRequestedLayer();
		} catch (Exception $e) {
			return static::throwInvalidRequestError($e->getMessage());
		}

		return static::respond('syncedToDisk', [
			'results' => $layer->syncToDisk()
		]);
	}

	public static function handleSyncFromDiskRequest()
	{
		$GLOBALS['Session']->requireAccountLevel('Developer');

		if ($_SERVER['REQUEST_METHOD'] != 'POST') {
			return static::throwInvalidRequestError('Request must be POST');
		}

        // Disable diagnostics by default for this operation as a high volume of queries may be needed
        Site::$debug = !empty($_GET['debug']);

		try {
			$layer = static::getRequestedLayer();
		} catch (Exception $e) {
			return static::throwInvalidRequestError($e->getMessage());
		}

		return static::respond('syncedFromDisk', [
			'results' => $layer->syncFromDisk()
		]);
	}

	public static function handlePullRequest()
	{
		$GLOBALS['Session']->requireAccountLevel('Developer');

		if ($_SERVER['REQUEST_METHOD'] != 'POST') {
			return static::throwInvalidRequestError('Request must be POST');
		}

		try {
			$layer = static::getRequestedLayer();
		} catch (Exception $e) {
			return static::throwInvalidRequestError($e->getMessage());
		}

		return static::handleExecuteCommandRequest($layer, 'merge', ['--ff-only', '@{upstream}']);
	}

	public static function handleExecuteCommandRequest(Layer $layer, $command, $args = [])
	{
		return static::respond('commandExecuted', [
			'layer' => $layer,
			'command' => $command,
			'args' => $args,
			'output' => $layer->getGitWrapper()->run($command, $args)
		]);
	}
}