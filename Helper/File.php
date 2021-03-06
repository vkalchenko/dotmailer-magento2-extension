<?php

namespace Dotdigitalgroup\Email\Helper;

class File
{
    const FILE_FULL_ACCESS_PERMISSION = '777';

    /**
     * @var string
     */
    public $outputFolder;
    /**
     * @var string
     */
    public $outputArchiveFolder;

    /**
     * @var string
     */
    public $delimiter;
    /**
     * @var string
     */
    public $enclosure;
    /**
     * @var Data
     */
    public $helper;

    /**
     * File constructor.
     *
     * @param Data                                            $helper
     * @param \Magento\Framework\App\Filesystem\DirectoryList $directoryList
     * @param \Magento\Framework\Filesystem                   $filesystem
     */
    public function __construct(
        \Dotdigitalgroup\Email\Helper\Data $helper,
        \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        \Magento\Framework\Filesystem $filesystem
    ) {
        $this->helper              = $helper;
        $this->directoryList       = $directoryList;
        $this->filesystem          = $filesystem;
        $var                       = $directoryList->getPath('var');
        $this->outputFolder        = $var . DIRECTORY_SEPARATOR . 'export'
            . DIRECTORY_SEPARATOR . 'email';
        $this->outputArchiveFolder = $this->outputFolder
            . DIRECTORY_SEPARATOR . 'archive';

        $this->delimiter = ','; // tab character
        $this->enclosure = '"';
    }

    /**
     * @return string
     */
    public function getOutputFolder()
    {
        $this->pathExists($this->outputFolder);

        return $this->outputFolder;
    }

    /**
     * @return string
     */
    public function getArchiveFolder()
    {
        $this->pathExists($this->outputArchiveFolder);

        return $this->outputArchiveFolder;
    }

    /**
     *  Return the full filepath.
     *
     * @param string $filename
     *
     * @return string
     */
    public function getFilePath($filename)
    {
        return $this->getOutputFolder() . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Move file to archive dir.
     *
     * @param $filename
     */
    public function archiveCSV($filename)
    {
        $this->moveFile(
            $this->getOutputFolder(),
            $this->getArchiveFolder(),
            $filename
        );
    }

    /**
     * Moves the output file from one folder to the next.
     *
     * @param $sourceFolder
     * @param $destFolder
     * @param $filename
     */
    public function moveFile($sourceFolder, $destFolder, $filename)
    {
        // generate the full file paths
        $sourceFilepath = $sourceFolder . DIRECTORY_SEPARATOR . $filename;
        $destFilepath = $destFolder . DIRECTORY_SEPARATOR . $filename;

        // rename the file
        //@codingStandardsIgnoreStart
        rename($sourceFilepath, $destFilepath);
        //@codingStandardsIgnoreEnd
    }

    /**
     * Output an array to the output file FORCING Quotes around all fields.
     *
     * @param $filepath
     * @param $csv
     *
     * @throws \Exception
     */
    public function outputForceQuotesCSV($filepath, $csv)
    {
        //@codingStandardsIgnoreStart
        $fqCsv = $this->arrayToCsv($csv, chr(9), '"', true, false);
        // Open for writing only; place the file pointer at the end of the file.
        // If the file does not exist, attempt to create it.
        $fp = fopen($filepath, 'a');

        // for some reason passing the preset delimiter/enclosure variables results in error
        // $this->delimiter $this->enclosure
        if (fwrite($fp, $fqCsv) == 0) {
            throw new \Exception('Problem writing CSV file');
        }
        fclose($fp);
        //@codingStandardsIgnoreEnd
    }

    /**
     * @param $filepath
     * @param $csv
     */
    public function outputCSV($filepath, $csv)
    {
        /*
         * Open for writing only; place the file pointer at the end of the file.
         * If the file does not exist, attempt to create it.
         */
        //@codingStandardsIgnoreStart
        $handle = fopen($filepath, 'a');
        fputcsv($handle, $csv, ',', '"');
        fclose($handle);
        //@codingStandardsIgnoreEnd
    }

    /**
     * If the path does not exist then create it.
     *
     * @param $path
     */
    public function pathExists($path)
    {
        //@codingStandardsIgnoreStart
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        //@codingStandardsIgnoreEnd
    }

    /**
     * Convert array into the csv.
     *
     * @param array $fields
     * @param       $delimiter
     * @param       $enclosure
     * @param bool $encloseAll
     * @param bool $nullToMysqlNull
     *
     * @return string
     */
    public function arrayToCsv(
        array &$fields,
        $delimiter,
        $enclosure,
        $encloseAll = false,
        $nullToMysqlNull = false
    ) {
        $delimiterEsc = preg_quote($delimiter, '/');
        $enclosureEsc = preg_quote($enclosure, '/');

        $output = [];
        foreach ($fields as $field) {
            if ($field === null && $nullToMysqlNull) {
                $output[] = 'NULL';
                continue;
            }

            // Enclose fields containing $delimiter, $enclosure or whitespace
            if ($encloseAll
                || preg_match(
                    "/(?:${delimiterEsc}|${enclosureEsc}|\s)/",
                    $field
                )
            ) {
                $output[] = $enclosure . str_replace(
                    $enclosure,
                    $enclosure . $enclosure,
                    $field
                ) . $enclosure;
            } else {
                $output[] = $field;
            }
        }

        return implode($delimiter, $output) . "\n";
    }

    /**
     * Delete file or directory.
     *
     * @param $path
     *
     * @return bool
     */
    public function deleteDir($path)
    {
        $classFunc = [__CLASS__, __FUNCTION__];
        //@codingStandardsIgnoreStart
        return is_file($path)
            ?
            @unlink($path)
            :
            array_map($classFunc, glob($path . '/*')) == @rmdir($path);
        //@codingStandardsIgnoreEnd
    }
}
