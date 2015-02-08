<?php
namespace DevSwert\LaCrud\Data\Repository;

use Doctrine\DBAL\Types\Type;
use DevSwert\LaCrud\Data\BaseTable;

abstract class LaCrudBaseRepository {

    public $entity;
    public $fieldsNotSee = array();
    public $displayAs = array();
    public $isPassword = array();
    public $requiredFields = array();
    public $unsetTextEditor = array();
    public $nameDisplayForeignsKeys = array();
    public $fakeRelation = array();
    public $manyRelations = array();
    protected $queryBuilder;
    protected $all = false;
    protected $enumFields = array();

    abstract public function like($field,$value);
    abstract public function where($field,$operation,$value);
    abstract public function limit($limit);
    abstract public function orderBy($field,$order);
    abstract public function orLike($field,$value);
    abstract public function orWhere($field,$operation,$value);
    abstract public function get();

    final public function find($field,$value){
    	return $this->entity->where($field,'=',$value)->first();
    }

    final public function getColumns(){

        //echo "<pre>";
        //dd( Type::getTypesMap() );

        $connection = \DB::connection()->getDoctrineSchemaManager($this->entity->table);
        $connection->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'enum');

        //echo "<pre>";
        //dd( get_class_methods($connection) );
        //dd( $connection->listTableColumns('posts') );

        return $connection->listTableColumns($this->entity->table);
    }

    final public function getPrimaryKey(){
        
        

        $connection = \DB::connection()->getDoctrineSchemaManager($this->entity->table);
        $connection->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'enum');
        $table = $connection->listTableDetails($this->entity->table);

        // echo "<pre>";
        // $tmp = $table->getForeignKeys();
        // dd( get_class_methods($tmp['posts_user_id_foreign']) );

        if($table->hasPrimaryKey()){
            $field = $table->getPrimaryKey()->getColumns();
            return $field[0];
        }
        return false;
    }

    final public function getForeignKeys(){
        $connection = \DB::connection()->getDoctrineSchemaManager($this->entity->table);
        return $connection->listTableForeignKeys($this->entity->table);
    }

    final public function getHeaders($columns,$withDisplayAs = false){
        $response = array();
        foreach($columns as $column){
            if(!in_array($column->getName(),$this->fieldsNotSee)){
                $accept = false;

                if( array_key_exists($column->getName(),$this->displayAs)){
                    if(  !in_array($this->displayAs[$column->getName()], $this->fieldsNotSee) ){
                        $accept = true;
                    }
                }
                else{
                    $accept = true;
                }

                if($accept){
                    if($withDisplayAs){
                        $name = (array_key_exists($column->getName(),$this->displayAs)) ? $this->displayAs[$column->getName()] : $column->getName();
                        $name = ucfirst(str_replace('_',' ',$name ));
                    }
                    else
                        $name = $column->getName();
                    array_push($response,$name);
                }
            }
        }
        array_push($response, 'Actions');
        return $response;
    }

    final public function getTypes($columns){
        $response = array();
        foreach($columns as $column) {
            array_push($response,$column->getName());
        }
        return $response;
    }

    final public function filterData($data){
        $collection = array();
        foreach($data as $row){
            $object = new BaseTable();
            foreach ($row['attributes'] as $key => $value) {
                if(!in_array($key, $this->fieldsNotSee)){
                    if( array_key_exists($key, $this->nameDisplayForeignsKeys) ){
                        $value = $this->searchAliasValue($key,$value);
                    }
                    if( array_key_exists($key, $this->fakeRelation) ){
                        $value = $this->searchFakeValue($key,$value);
                    }
                    if(array_key_exists($key, $this->displayAs) ){
                        if(!in_array($this->displayAs[$key], $this->fieldsNotSee)){
                            $object->{$key} = $value;
                        }
                    }
                    else{
                        $object->$key = $value;
                    }
                }
            }
            array_push($collection, $object);
        }
        return $collection;
    }

    final public function findIsForeignKey($column,$foreignsKeys){
        $response = $this->findNativeRealtion($column,$foreignsKeys);
        if(count($response) == 0){
            $response = $this->findFakeRelation($column);
        }
        return $response;
    }

    final private function findNativeRealtion($column,$foreignsKeys){
        $response = array();
        foreach ($foreignsKeys as $key){
            if($column->getName() === $key->getLocalColumns()[0]){
                $display = ( array_key_exists($column->getName(), $this->nameDisplayForeignsKeys) ) ? $this->nameDisplayForeignsKeys[$column->getName()] : null;
                $response = $this->getValuesForeignKey($key->getForeignTableName(),$key->getForeignColumns()[0],$display);
                break;
            }
        }
        return $response;
    }

    final private function findFakeRelation($column){
        if( array_key_exists($column->getName(), $this->fakeRelation) ){
            return $this->getValuesForeignKey(
                $this->fakeRelation[$column->getName()]['table'],
                $this->fakeRelation[$column->getName()]['field'],
                $this->fakeRelation[$column->getName()]['alias']
            );
        }
        else
            return array();
    }

    final private function getValuesForeignKey($table,$pk,$alias = null){
        $options = array();
        $query = \DB::table($table)->select($pk);
        if(!is_null($alias))
            $query->addSelect($alias);
        foreach ($query->get() as $row){
            if(!is_null($alias))
                $options[$row->{$alias}] = $row->{$pk};
            else
                array_push($options, $row->{$pk});
        }
        return $options;
    }

    final public function searchAliasValue($field,$value){
        $foreignsKeys = $this->getForeignKeys();
        foreach ($foreignsKeys as $key){
            if($field === $key->getLocalColumns()[0]){
                $data = \DB::table($key->getForeignTableName())->where($key->getForeignColumns()[0],'=',$value)->groupBy($key->getForeignColumns()[0])->first();
                break;
            }
        }
        if(isset($data)){
            return $data->{$this->nameDisplayForeignsKeys[$field]};
        }
        return $value;
    }

    final public function searchFakeValue($original,$value){
        $table = $this->fakeRelation[$original]['table'];
        $field = $this->fakeRelation[$original]['field'];
        $alias = ( array_key_exists('alias', $this->fakeRelation[$original]) ) ? $this->fakeRelation[$original]['alias'] : null;

        $data = \DB::table($table)->where($field,'=',$value)->groupBy($field)->first();
        if(isset($data)){
            return $data->$alias;
        }
        return $value;
    }

    //Enum utils
    final public function findEnumFields($column){
        switch (\DB::connection()->getConfig('driver')) {
			case 'pgsql':
				$query = "SELECT column_name FROM information_schema.columns WHERE table_name = '".$this->entity->table."'";
				$column_name = 'column_name';
				$reverse = true;
				break;
 
		case 'mysql':
			$query = 'SHOW COLUMNS FROM '.$this->entity->table.' WHERE Field = "'.$column->getName().'"';
			$column_name = 'Field';
			$reverse = false;
			break;
 
		case 'sqlsrv':
			$parts = explode('.', $this->entity->table);
			$num = (count($parts) - 1);
			$table = $parts[$num];
			$query = "SELECT column_name FROM ".\DB::connection()->getConfig('database').".INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = N'".$table."'";
			$column_name = 'column_name';
			$reverse = false;
			break;
 
		default:
			$error = 'Database driver not supported: '.\DB::connection()->getConfig('driver');
			throw new Exception($error);
			break;
		}
 
		$columns = array(); 
		foreach(\DB::select($query) as $column){
			preg_match('/^enum\((.*)\)$/', $column->Type, $matches);

			if( is_array($matches) && count($matches) == 2 ){
				$columns = $this->getEnumValues(explode(',',$matches[1]));
			}
		}
 		if($reverse){
			$columns = array_reverse($columns);
		}
		return $columns;
    }

    final private function getEnumValues($elements){
        $enum = array();
        foreach( $elements as $value ){
            array_push($enum, trim(trim( $value, "'" )," "));
        }
        return $enum;
    }

    final public function getOptionsEnum($column){
    	if($column->getType()->getName() == 'enum'){
    		return $this->findEnumFields($column);
    	}
    	return array();
    }

}
