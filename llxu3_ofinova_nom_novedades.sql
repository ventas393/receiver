-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost:3306
-- Tiempo de generación: 15-05-2026 a las 13:30:01
-- Versión del servidor: 11.4.10-MariaDB-cll-lve-log
-- Versión de PHP: 8.4.21

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `ofinovac_doli904`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `llxu3_ofinova_nom_novedades`
--

CREATE TABLE `llxu3_ofinova_nom_novedades` (
  `rowid` int(11) NOT NULL,
  `fk_soc` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `tipo` varchar(30) DEFAULT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `monto` double DEFAULT 0,
  `base_salarial` tinyint(4) DEFAULT 1,
  `fk_periodo` int(11) DEFAULT NULL,
  `status` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Volcado de datos para la tabla `llxu3_ofinova_nom_novedades`
--

INSERT INTO `llxu3_ofinova_nom_novedades` (`rowid`, `fk_soc`, `fecha`, `tipo`, `descripcion`, `monto`, `base_salarial`, `fk_periodo`, `status`) VALUES
(24, 41, '2026-01-01', 'Anticipo', 'Compra en comercio internacional', 170421.15, 1, 15, 1),
(25, 55, '2026-01-02', 'Anticipo', 'Compra BOLD*Movil y partes', 115000, 1, 15, 1),
(26, 41, '2026-01-02', 'Anticipo', 'Pagos por Internet BANCOLOMBIA', 36000, 1, 15, 1),
(27, 41, '2026-01-03', 'Anticipo', 'Compra LA VERBENA RESTO BAR S', 99000, 1, 15, 1),
(28, 41, '2026-01-02', 'Anticipo', 'Compra BOLD*Gordo Tecnologi', 60000, 1, 15, 0),
(29, 41, '2026-01-03', 'Anticipo', 'Compra TIENDAS D1 SINCELEJO P', 17040, 1, 15, 0),
(30, 41, '2026-01-04', 'Anticipo', 'Compra TIENDAS ARA', 11980, 1, 15, 0),
(31, 41, '2026-01-05', 'Anticipo', 'Compra PRIME VIDEO DL', 19900, 1, 15, 0),
(32, 41, '2026-01-05', 'Anticipo', 'Compra en comercio internacional', 4900, 1, 15, 0),
(33, 55, '2026-01-17', 'Anticipo', 'Compra BOLD*Torres Gourmet', 90000, 1, 15, 0),
(34, 41, '2026-01-17', 'Anticipo', 'Compra DLO*help.hbomax.com', 16900, 1, 15, 0),
(35, 41, '2026-01-18', 'Anticipo', 'Compra SUPERTIENDA OLIMPICA 3', 5850, 1, 15, 0),
(36, 55, '2026-01-18', 'Anticipo', 'Compra TIENDAS ARA', 15760, 1, 15, 0),
(37, 41, '2026-01-19', 'Anticipo', 'Compra AMAZON PRIME', 24900, 1, 15, 0),
(38, 41, '2026-01-20', 'Anticipo', 'Compra BOLD*Restaurante los', 80000, 1, 15, 0),
(39, 55, '2026-01-20', 'Anticipo', 'Compra TIENDA D1 SANTA CATALI', 12620, 1, 15, 0),
(40, 55, '2026-01-20', 'Anticipo', 'Compra en comercio internacional', 19900, 1, 15, 0),
(41, 41, '2026-01-21', 'Anticipo', 'Compra DOLLARCITY MATUNA', 16000, 1, 15, 0),
(42, 41, '2026-01-23', 'Anticipo', 'Compra GOOGLE *PLAY YOUTUBE*D', 41900, 1, 15, 0),
(43, 55, '2026-01-24', 'Anticipo', 'Compra TIENDAS ARA', 22710, 1, 15, 0),
(44, 41, '2026-01-25', 'Anticipo', 'Compra MERAKI SUSHI', 106000, 1, 15, 0),
(45, 55, '2026-01-26', 'Anticipo', 'Compra en comercio internacional', 36900, 1, 15, 0),
(46, 41, '2026-01-26', 'Anticipo', 'Compra MERMAS MERKFRUVER TURB', 72015, 1, 15, 0),
(47, 55, '2026-01-26', 'Anticipo', 'Pagos por Internet BANCOLOMBIA', 100000, 1, 15, 0),
(48, 55, '2026-01-26', 'Anticipo', 'Compra RAPPI COLOMBIA*DL', 23490, 1, 15, 0),
(49, 41, '2026-01-28', 'Anticipo', 'Compra PRIME VIDEO DL', 24900, 1, 15, 0),
(50, 55, '2026-01-28', 'Anticipo', 'Pagos por Internet BANCOLOMBIA', 100000, 1, 15, 0),
(51, 55, '2026-01-28', 'Anticipo', 'Compra SPOTIFY', 18500, 1, 15, 0),
(52, 41, '2026-01-29', 'Anticipo', 'Compra PRIME VIDEO DL', 16000, 1, 15, 0),
(53, 55, '2026-01-29', 'Anticipo', 'Pagos por Internet BANCOLOMBIA', 30000, 1, 15, 0),
(54, 41, '2026-01-29', 'Anticipo', 'Compra MERMAS MERKFRUVER TURB', 176915, 1, 15, 0),
(55, 55, '2026-01-29', 'Anticipo', 'Pagos por Internet BANCOLOMBIA', 30000, 1, 15, 0),
(56, 41, '2026-01-30', 'Anticipo', 'Compra DOLLARCITY PLAZA 90', 246000, 1, 15, 0),
(57, 55, '2026-01-31', 'Anticipo', 'Pagos por Internet 000000000000000000', 100000, 1, 15, 0);

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `llxu3_ofinova_nom_novedades`
--
ALTER TABLE `llxu3_ofinova_nom_novedades`
  ADD PRIMARY KEY (`rowid`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `llxu3_ofinova_nom_novedades`
--
ALTER TABLE `llxu3_ofinova_nom_novedades`
  MODIFY `rowid` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
