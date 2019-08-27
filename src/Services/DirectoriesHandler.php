<?php

namespace App\Services;

use App\Controller\Files\FileUploadController;
use App\Controller\Utils\Application;
use App\Controller\Utils\Utils;
use DirectoryIterator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * This service is responsible for handling folders in terms of internal usage, like moving/renaming/etc...
 * Class DirectoriesHandler
 * @package App\Services
 */
class DirectoriesHandler {

    const SUBDIRECTORY_KEY = 'subdirectory';

    /**
     * @var Application $application
     */
    private $application;

    /**
     * @var LoggerInterface $logger
     */
    private $logger;

    public function __construct(Application $application, LoggerInterface $logger) {
        $this->application = $application;
        $this->logger      = $logger;
    }


    /**
     * @Route("/upload/{upload_type}/remove-subdirectory", name="upload_remove_subdirectory", methods="POST")
     * @param string $upload_type
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function removeFolderByPostRequest(string $upload_type, Request $request){

        if ( !$request->query->has(FileUploadController::KEY_SUBDIRECTORY_CURRENT_PATH_IN_UPLOAD_DIR) ) {
            return new Response("Subdirectory location is missing in request.");
        }

        $current_directory_path_in_upload_type_dir  = $request->query->get(FileUploadController::KEY_SUBDIRECTORY_CURRENT_PATH_IN_UPLOAD_DIR);
        $response                                   = $this->removeFolder($upload_type, $current_directory_path_in_upload_type_dir);

        return $response;
    }


    /**
     * @param string $upload_type
     * @param string $current_directory_path_in_upload_type_dir
     * @return Response
     * @throws \Exception
     */
    public function removeFolder(?string $upload_type, ?string $current_directory_path_in_upload_type_dir) {

        $subdirectory_name = basename($current_directory_path_in_upload_type_dir);

        $this->logger->info('Started removing folder: ', [
            'upload_type'       => $upload_type,
            'subdirectory_name' => $subdirectory_name,
            'current_directory_path_in_upload_type_dir' => $current_directory_path_in_upload_type_dir
        ]);

        if( empty($subdirectory_name) )
        {
            return new Response('Cannot remove main folder!', 500);
        }

        if( empty($upload_type) )
        {
            return new Response('You need to select upload type!', 500);
        }

        $target_directory           = FileUploadController::getTargetDirectoryForUploadType($upload_type);
        $is_subdirectory_existing   = !FileUploadController::isSubdirectoryForTypeExisting($target_directory, $current_directory_path_in_upload_type_dir);
        $subdirectory_path             = $target_directory.'/'.$current_directory_path_in_upload_type_dir;

        if( $is_subdirectory_existing ){
            $this->logger->info('Removed folder does not exists - removal aborted');
            return new Response('This subdirectory does not exist for current upload type.', 500);
        }

        try{
            Utils::removeFolderRecursively($subdirectory_path);
        }catch(\Exception $e){
            $this->logger->info('Could not remove folder: ', [
                'message' => $e->getMessage()
            ]);
            return new Response('There was and error when trying to remove subdirectory!', 500);
        }

        $this->logger->info('Finished removing folder.');
        return new Response('Subdirectory has been successfully removed.');

    }

    /**
     * @Route("/upload/{upload_type}/rename-subdirectory", name="upload_rename_subdirectory", methods="POST")
     * @param string $upload_type
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function renameSubdirectoryByPostRequest(string $upload_type, Request $request) {

        if ( !$request->query->has(FileUploadController::KEY_SUBDIRECTORY_NEW_NAME) ) {
            return new Response("Subdirectory new name is missing in request.", 500);
        }

        if ( !$request->query->has(FileUploadController::KEY_SUBDIRECTORY_CURRENT_NAME) ) {
            return new Response("Subdirectory current name is missing in request.", 500);
        }

        $current_directory_path_in_upload_type_dir  = $request->query->get(FileUploadController::KEY_SUBDIRECTORY_CURRENT_PATH_IN_UPLOAD_DIR);
        $subdirectory_new_name                      = $request->query->get(FileUploadController::KEY_SUBDIRECTORY_NEW_NAME);

        $response = $this->renameSubdirectory($upload_type, $current_directory_path_in_upload_type_dir, $subdirectory_new_name);

        return $response;
    }

    /**
     * @param string $upload_type
     * @param string $current_directory_path_in_upload_type_dir
     * @param string $subdirectory_new_name
     * @return Response
     * @throws \Exception
     */
    public function renameSubdirectory(?string $upload_type, ?string $current_directory_path_in_upload_type_dir, ?string $subdirectory_new_name) {

        $subdirectory_current_name = basename($current_directory_path_in_upload_type_dir);

        $this->logger->info('Started renaming subdirectory: ', [
            'upload_type'               => $upload_type,
            'subdirectory_current_name' => $subdirectory_current_name,
            'subdirectory_new_name'     => $subdirectory_new_name,
            'current_directory_path_in_upload_type_dir' => $current_directory_path_in_upload_type_dir
        ]);

        if( $subdirectory_current_name === $subdirectory_new_name ){
            $this->logger->info('Subdirectory name will not change - renaming aborted.');
            return new Response("You are trying to change folder name to the same that there already is - action aborted.", 500);
        }

        if ( empty($subdirectory_new_name) ){
            $this->logger->info('Subdirectory new name is an empty string - renaming aborted.');
            return new Response('New name is an empty string - action aborted', 500);
        }

        if ( empty($subdirectory_current_name) ){
            $this->logger->info('Subdirectory current name is an empty string - renaming aborted.');
            return new Response('Current name is an empty string - action aborted', 500);
        }

        if ( empty($upload_type) ){
            $this->logger->info('Upload type has not been provided - renaming aborted.');
            return new Response('Upload type is an empty string - action aborted', 500);
        }

        $target_directory       = FileUploadController::getTargetDirectoryForUploadType($upload_type);
        $subdirectory_exists    = FileUploadController::isSubdirectoryForTypeExisting($target_directory, $current_directory_path_in_upload_type_dir);

        $current_directory_path = $target_directory.'/'.$current_directory_path_in_upload_type_dir;
        $parent_subdirectories  = dirname($current_directory_path);
        $new_directory_path     = $parent_subdirectories . '/' . $subdirectory_new_name;

        if( !file_exists($current_directory_path) ){
            $this->logger->info("Target directory for which user tried to change name does not exist");
            return new Response("Target directory for which You try to change name does not exist", 500);
        }

        if( !$subdirectory_exists ){
            $message = "Subdirectory with this name does not exist!";
            $this->logger->info($message);
            return new Response($message, 500);
        }

        $subdirectory_with_new_name_exists = FileUploadController::isSubdirectoryForTypeExisting($parent_subdirectories, $subdirectory_new_name);

        if( $subdirectory_with_new_name_exists ){
            $this->logger->info('Subdirectory with this name already exists - renaming aborted.');
            return new Response(" Cannot change subdirectory name! Subdirectory with this name already exist.", 500);
        }

        try{
            rename($current_directory_path, $new_directory_path);
        }catch(\Exception $e){
            $this->logger->info('Exception was thrown while renaming folder: ', [
                'message' => $e->getMessage()
            ]);

            return new Response('There was an error when renaming the folder! Error message. Most likely due to unallowed characters used in name.', 500);
        }

        $this->logger->info('Finished renaming subdirectory.');
        return new Response('Folder name has been successfully changed', 200);

    }

    /**
     * @param string $upload_type
     * @param string $subdirectory_name
     * @return Response
     * @throws \Exception
     */
    public function createFolder(string $upload_type, string $subdirectory_name){

        $this->logger->info('Started creating subdirectory: ', [
            'upload_type'       => $upload_type,
            'subdirectory_name' => $subdirectory_name
        ]);

        $target_directory       = FileUploadController::getTargetDirectoryForUploadType($upload_type);
        $full_subdir_path       = FileUploadController::getSubdirectoryPath($target_directory, $subdirectory_name);

        if( file_exists($full_subdir_path) ){
            $this->logger->info('Subdirectory with this name already exists.');
            return new Response('Subdirectory with this name for selected upload typ already exists.', 500);
        }

        try {
            mkdir($full_subdir_path, 0777);
        } catch (\Exception $e) {
            $this->logger->info('Exception was thrown while creating folder: ', [
                'message' => $e->getMessage()
            ]);

            return new Response('There was an error while trying to create new folder for given upload type', 500);
        }

        $this->logger->info('Finished creating subdirectory.');
        return new Response ('Subdirectory for selected upload_type has been successfully created', 200);
    }

    /**
     * @param DirectoryIterator $dir
     * @param bool $use_foldername
     * @return array
     */
    public static function buildFoldersTreeForDirectory(DirectoryIterator $dir, bool $use_foldername = false): array
    {
        $data = [];
        foreach ( $dir as $node )
        {
            if ( $node->isDir() && !$node->isDot() )
            {
                $pathname        = $node->getPathname();
                $foldername      = $node->getFilename();
                $key             = ( $use_foldername ? $foldername : $pathname);

                $data[$key] = static::buildFoldersTreeForDirectory( new DirectoryIterator( $pathname ) );
            }

        }
        return $data;
    }

    public static function buildFoldersTreeForDirectories(array $directories, bool $use_foldername = false): array {
        $directories_trees = [];

        foreach ($directories as $directory) {
            $directory_tree                 = DirectoriesHandler::buildFoldersTreeForDirectory( new DirectoryIterator( $directory ), $use_foldername );
            $directories_trees[$directory]  = $directory_tree;
        }

        return $directories_trees;
    }

}