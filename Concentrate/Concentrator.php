<?php

require_once 'Concentrate/DataProvider.php';

class Concentrate_Concentrator
{
	protected $dataProvider = null;

	protected $packageSortOrder = null;

	protected $fileSortOrder = null;

	protected $fileInfo = null;

	protected $combinesInfo = null;

	public function __construct(array $options = array())
	{
		if (array_key_exists('dataProvider', $options)) {
			$this->setDataProvider($options['dataProvider']);
		} elseif (array_key_exists('data_provider', $options)) {
			$this->setDataProvider($options['data_provider']);
		} else {
			$this->setDataProvider(new Concentrate_DataProvider());
		}
	}

	public function setDataProvider(Concentrate_DataProvider $dataProvider)
	{
		$this->dataProvider = $dataProvider;
		$this->clearCachedValues();
		return $this;
	}

	public function loadDataFile($filename)
	{
		$this->dataProvider->loadDataFile($filename);
		$this->clearCachedValues();
		return $this;
	}

	public function loadDataFiles(array $filenames)
	{
		foreach ($filenames as $filename) {
			$this->loadDataFile($filename);
		}
		return $this;
	}

	public function loadDataArray(array $data)
	{
		$this->dataProvider->loadDataArray($data);
		$this->clearCachedValues();
		return $this;
	}

	public function compareFiles($file1, $file2)
	{
		if ($file1 == $file2) {
			return 0;
		}

		$sortOrder = $this->getFileSortOrder();

		if (!isset($sortOrder[$file1]) && !isset($sortOrder[$file2])) {
			return 0;
		}

		if (isset($sortOrder[$file1]) && !isset($sortOrder[$file2])) {
			return -1;
		}

		if (!isset($sortOrder[$file1]) && isset($sortOrder[$file2])) {
			return 1;
		}

		if ($sortOrder[$file1] < $sortOrder[$file2]) {
			return -1;
		}

		if ($sortOrder[$file1] > $sortOrder[$file2]) {
			return 1;
		}

		return 0;
	}

	public function getConflicts(array $files)
	{
		$conflicts = array();

		// flip so the files are hash keys to speed lookups
		$files = array_flip($files);

		$fileInfo = $this->getFileInfo();

		foreach ($files as $file => $garbage) {
			if (array_key_exists($file, $fileInfo)) {
				$fileFileInfo = $fileInfo[$file];
				if (   isset($fileFileInfo['Conflicts'])
					&& is_array($fileFileInfo['Conflicts'])
				) {
					foreach ($fileFileInfo['Conflicts'] as $conflict) {
						if (array_key_exists($conflict, $files)) {
							if (!isset($conflicts[$file])) {
								$conflicts[$file] = array();
							}
							$conflicts[$file][] = $conflict;
						}
					}
				}
			}
		}

		return $conflicts;
	}

	public function getCombines(array $files)
	{
		$superset = array();
		$combines = array();

		$combinesInfo = $this->getCombinesInfo();
		foreach ($combinesInfo as $combine => $combinedFiles) {

			// check if combine does not conflict with existing set and if
			// combine contains one or more files in the required file list
			if (   count(array_intersect($combinedFiles, $superset)) === 0
				&& count(array_intersect($combinedFiles, $files)) > 0
			) {
				$superset   = array_merge($superset, $combinedFiles);
				$combines[] = $combine;
			}

		}

		// add files not included in the combines to the superset
		$superset = array_unique(array_merge($files, $superset));

		// exclude contents of combined sets from file list
		foreach ($combines as $combine) {
			$files = array_diff($files, $combinesInfo[$combine]);
			$files[] = $combine;
		}

		$info = array(
			// 'combines' contains the combined files that will be included.
			'combines' => $combines,

			// 'superset' contains all original files plus files pulled in by
			// the combined sets. The content of these files will be included.
			'superset' => $superset,

			// 'files' contains combined files and files in the original set
			// that did not fit in any combined set. These are the actual files
			// that will be included.
			'files'    => $files,
		);

		return $info;
	}

	// {{{ protected function getPackageSortOrder()

	protected function getPackageSortOrder()
	{
		if ($this->packageSortOrder === null) {

			$data = $this->dataProvider->getData();

			// get flat list of package dependencies for each package
			$packageDependencies = array();
			foreach ($data as $packageId => $info) {
				if (!isset($packageDependencies[$packageId])) {
					$packageDependencies[$packageId] = array();
				}
				if (isset($info['Depends'])) {
					$packageDependencies[$packageId] = array_merge(
						$packageDependencies[$packageId],
						$info['Depends']
					);
				}
			}

			// build into a tree (tree will contain redundant info)
			$tree = array();
			foreach ($packageDependencies as $packageId => $dependencies) {
				if (!isset($tree[$packageId])) {
					$tree[$packageId] = array();
				}
				foreach ($dependencies as $dependentPackageId) {
					if (!isset($tree[$dependentPackageId])) {
						$tree[$dependentPackageId] = array();
					}
					$tree[$packageId][$dependentPackageId] =&
						$tree[$dependentPackageId];
				}
			}

			// traverse tree to filter out redundant info and get final order
			$order = array();
			$order = $this->filterTree($tree, $order);

			// return indexed by package id, with values being the relative
			// sort order
			$this->packageSortOrder = array_flip($order);

		}

		return $this->packageSortOrder;
	}

	// }}}
	// {{{ protected function getFileSortOrder()

	protected function getFileSortOrder()
	{
		if ($this->fileSortOrder === null) {

			$data = $this->dataProvider->getData();

			$fileSortOrder = array();

			// sort each package in order
			foreach ($this->getPackageSortOrder() as $packageId => $order) {

				if (!isset($data[$packageId])) {
					continue;
				}

				$info = $data[$packageId];

				// get flat list of file dependencies for each file
				$fileDependencies = array();

				if (isset($info['Provides']) && is_array($info['Provides'])) {
					foreach ($info['Provides'] as $file => $fileInfo) {
						if (!isset($fileDependencies[$file])) {
							$fileDependencies[$file] = array();
						}
						if (isset($fileInfo['Depends'])) {
							$fileDependencies[$file] = array_merge(
								$fileDependencies[$file],
								$fileInfo['Depends']
							);
						}
						if (isset($fileInfo['OptionalDepends'])) {
							$fileDependencies[$file] = array_merge(
								$fileDependencies[$file],
								$fileInfo['OptionalDepends']
							);
						}
					}
				}

				// build into a tree (tree will contain redundant info)
				$tree = array();
				foreach ($fileDependencies as $file => $dependencies) {
					if (!isset($tree[$file])) {
						$tree[$file] = array();
					}
					foreach ($dependencies as $dependentFile) {
						if (!isset($tree[$dependentFile])) {
							$tree[$dependentFile] = array();
						}
						$tree[$file][$dependentFile] =& $tree[$dependentFile];
					}
				}

				// traverse tree to filter out redundant info and get order
				$order = array();
				$order = $this->filterTree($tree, $order);

				$fileSortOrder = array_merge(
					$fileSortOrder,
					$order
				);
			}

			// index by file, with values being the relative sort order
			$fileSortOrder = array_flip($fileSortOrder);

			// add combines as dependencies of all contained files
			$combines = false;
			foreach ($data as $package_id => $info) {
				if (isset($info['Combines']) && is_array($info['Combines'])) {
					foreach ($info['Combines'] as $combine => $files) {
						foreach ($files as $file) {
							if (   !isset($fileSortOrder[$file])
								|| !is_array($fileSortOrder[$file])
							) {
								$fileSortOrder[$file] = array();
							}
							$fileSortOrder[$file][$combine] = array();
							$combines = true;
						}
					}
				}
			}

			// re-traverse to get dependency order of combines
			if ($combines) {
				$temp = array();
				$fileSortOrder = $this->filterTree(
					$fileSortOrder,
					$temp
				);

				// index by file, with values being the relative sort order
				$fileSortOrder = array_flip($fileSortOrder);
			}

			$this->fileSortOrder = $fileSortOrder;
		}

		return $this->fileSortOrder;
	}

	// }}}
	// {{{ protected function getFileInfo()

	protected function getFileInfo()
	{
		if ($this->fileInfo === null) {

			$data = $this->dataProvider->getData();

			$this->fileInfo = array();

			foreach ($data as $packageId => $info) {
				if (isset($info['Provides']) && is_array($info['Provides'])) {
					foreach ($info['Provides'] as $file => $fileInfo) {
						$fileInfo['Package'] = $packageId;
						$this->fileInfo[$file] = $fileInfo;
					}
				}
			}
		}

		return $this->fileInfo;
	}

	// }}}
	// {{{ protected function getCombinesInfo()

	protected function getCombinesInfo()
	{
		if ($this->combinesInfo === null) {

			$data = $this->dataProvider->getData();

			$this->combinesInfo = array();

			foreach ($data as $packageId => $info) {
				if (isset($info['Combines']) && is_array($info['Combines'])) {
					foreach ($info['Combines'] as $combine => $files) {
						// add entries to the set
						foreach ($files as $file) {
							// create entry for the combine set if it does not
							// exist
							if (!isset($this->combinesInfo[$combine])) {
								$this->combinesInfo[$combine] = array();
							}
							$this->combinesInfo[$combine][] = $file;
						}
					}
				}
			}

			// sort largest sets first
			uasort($this->combinesInfo, array($this, 'compareCombines'));
		}

		return $this->combinesInfo;
	}

	// }}}
	// {{{ protected function compareCombine()

	protected function compareCombines(array $combine1, array $combine2)
	{
		if (count($combine1) < count($combine2)) {
			return 1;
		}

		if (count($combine1) > count($combine2)) {
			return -1;
		}

		return 0;
	}

	// }}}
	// {{{ protected function clearCachedValues()

	protected function clearCachedValues()
	{
		$this->packageSortOrder = null;
		$this->fileSortOrder    = null;
		$this->fileInfo         = null;
		$this->combinesInfo     = null;
	}

	// }}}
	// {{{ protected function filterTree()

	/**
	 * Performs a depth-first traversal of the given tree and collects an
	 * array of unique values in the traversal order
	 *
	 * @param array $nodes
	 * @param array &$visited
	 *
	 * @return array
	 */
	protected function filterTree(array $nodes, array &$visited)
	{
		foreach ($nodes as $node => $childNodes) {
			if (is_array($childNodes)) {
				$this->filterTree($childNodes, $visited);
			}
			if (!in_array($node, $visited)) {
				$visited[] = $node;
			}
		}

		return $visited;
	}

	// }}}
}

?>