<?php 

	interface iAuto {
		public function encender();
		public function apagar();
	}

	interface iCombustible {
		public function llenarTanque();
		public function vaciarTanque();
	}

	class Deportivo implements iAuto, iCombustible {

		public function encender(){
			echo "Hola";
		}

		public function apagar(){

		}
		
		public function llenarTanque(){
			echo "llenando tanque";
		}

		public function vaciarTanque(){

		}

	}

	// ---------------------------------
	$ferrari = new Deportivo();
	$ferrari->encender();
	$ferrari->llenarTanque();


?>