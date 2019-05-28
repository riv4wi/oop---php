<?php 

	 class Person {
	 	var $name;
	 	var $lastName;
	 	

	 	function __call($method, $arguments){
	 	  if ($method = 'set_name'){
	 	    if (count($arguments) == 1){
	 	    	$this->name = $arguments[0];
	 	    }
	 	    if (count($arguments) == 2){
	 	    	$this->name = $arguments[0];
	 	    	$this->lastName = $arguments[1];
	 	    }
	 	  }
	 	}

	 	public function get_name(){
	 		return $this->name;
	 	}
	 }

	 $persona1 = new Person;
	 $persona1->set_name('Albany');
	 var_dump($persona1);
	 $persona1->set_name('Albany', 'Rivas');
	 var_dump($persona1);
?>