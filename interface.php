<?php 

	interface iAuto {
		public function encender();
		public function apagar();
	}

	interface iCombustible extends iAuto {
		public function llenarTanque();
		public function vaciarTanque();
	}

	class Deportivo implements iCombustible {

		public function encender(){
			echo "Hola";
		}

		public function apagar(){

		}
		
		public function llenarTanque(){

		}

		public function vaciarTanque(){

		}

	}

	// ---------------------------------
	$ferrari = new Deportivo();
	$ferrari->encender();

?>