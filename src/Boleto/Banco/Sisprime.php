<?php

namespace Eduardokum\LaravelBoleto\Boleto\Banco;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Eduardokum\LaravelBoleto\Util;
use Eduardokum\LaravelBoleto\CalculoDV;
use Eduardokum\LaravelBoleto\Boleto\AbstractBoleto;
use Eduardokum\LaravelBoleto\Contracts\Boleto\BoletoAPI as BoletoContract;
use Eduardokum\LaravelBoleto\Exception\ValidationException;

class Sisprime extends AbstractBoleto implements BoletoContract
{
    
    /**
     * Código do banco
     * @var string
     */
    protected $codigoBanco = "084";

    /**
     * Define as carteiras disponíveis para este banco
     * @var array
     */
    protected $carteiras = ['9'];

    /**
     * Linha de local de pagamento
     *
     * @var string
     */
    protected $localPagamento = 'PAGÁVEL EM QUALQUER AGÊNCIA BANCÁRIA/CORRESPONDENTE BANCÁRIO';

    /**
     * ESPÉCIE DO DOCUMENTO: de acordo com o ramo de atividade
     * @var string
     */
    protected $especiesCodigo = [
        'DM'     => '01', //'Duplicata Mercantil',
        'NP'     => '02', //'Nota Promissória',
        'NS'     => '03', //'Nota de Seguro',
        'CS'     => '04', //'Cobrança Seriada',
        'REC'    => '05', //'Recibo',
        'LC'     => '10', //'Letras de Câmbio',
        'ND'     => '11', //'Nota de Débito',
        'DS'     => '12', //'Duplicata de Serviços',
        'BP'     => '30', // Boleto de Proposta
        'Outros' => '99',
    ];

    /**
     * Trata-se de código utilizado para identificar mensagens especificas ao cedente, sendo
     * que o mesmo consta no cadastro do Banco, quando não houver código cadastrado preencher
     * com zeros "000".
     *
     * @var int
     */
    protected $cip = '000';

    /**
     * Variaveis adicionais.
     *
     * @var array
     */
    public $variaveis_adicionais = [
        'cip'        => '000',
        'mostra_cip' => true,
    ];

    /**
     * Código do cliente (é código do cedente, também chamado de código do beneficiário) é o código do emissor junto ao banco e precisa ser buscado junto ao gerente de contas essa informação
     *
     * @var string
     */
    protected $codigoCliente;

    /**
     * Define o numero da variação da carteira.
     *
     * @var string|null
     */
    protected $variacao_carteira = null;
    
    /**
     * @return string
     */
    public function getID()
    {
        return $this->id;
    }
    
    /**
     * Define o número da variação da carteira.
     *
     * @param  string|null $variacao_carteira
     * @return Sisprime
     */
    public function setVariacaoCarteira($variacao_carteira)
    {
        $this->variacao_carteira = $variacao_carteira;

        return $this;
    }

    /**
     * Retorna o número da variacao de carteira
     *
     * @return string|null
     */
    public function getVariacaoCarteira()
    {
        return $this->variacao_carteira;
    }

    /**
     * Gera o Nosso Número. Formado com 11(onze) caracteres, sendo 10 dígitos
     * para o nosso número é um digito para o digito verificador. Ex.: 9999999999-D.
     * Obs.: O Nosso Número é um identificador do boleto, devendo ser atribuído
     * Nosso Número diferenciado para cada um.
     *
     * @return string
     */
    protected function gerarNossoNumero()
    {
        $numero = Util::numberFormatGeral($this->getNumero(), 11);
        $dv = CalculoDV::sisprimeNossoNumero($this->getCarteira().$numero);
        
        if($dv == 1){
            $dv = 'P';
        }else if($dv == 0){
            $dv = 0;
        }else{
            $dv = 11 - $dv;
        }
        $result = $numero . $dv;
        return $result;
    }


    /**
     * Método que retorna o nosso numero usado no boleto, formato XXXXXXXXXX-D. alguns bancos possuem algumas diferenças.
     *
     * @return string
     */
    public function getNossoNumeroBoleto()
    {
        return substr_replace($this->getNossoNumero(), '-', -1, 0);
    }

    /**
     * Método para gerar o código da posição de 20 a 44
     *
     * @return string
     */
    protected function getCampoLivre()
    {
        if ($this->campoLivre) {
            return $this->campoLivre;
        }

        $nossoNumero = $this->getNossoNumero();
        $campoLivre = Util::numberFormatGeral($this->getAgencia(), 4); //Agência BENEFICIÁRIO (Sem o dígito verificador, completar com zeros à esquerda quando necessário)
        $campoLivre .= Util::numberFormatGeral($this->getCarteira(), 2);
        $campoLivre .= Util::numberFormatGeral($nossoNumero, 11); //Nosso Número (Sem o dígito verificador)
        $campoLivre .= Util::numberFormatGeral($this->getConta(), 7); //Conta do BENEFICIÁRIO (Sem o dígito verificador - Completar com zeros à esquerda quando necessário)
        $campoLivre .= '0';
        return $this->campoLivre = $campoLivre;
    }

    /**
     * Método onde qualquer boleto deve extender para gerar o código da posição de 20 a 44
     *
     * @param $campoLivre
     *
     * @return array
     */
    public static function parseCampoLivre($campoLivre)
    {
        return [
            // 'convenio' => null,
            'agenciaDv'       => null,
            'contaCorrenteDv' => null,
            'agencia'         => substr($campoLivre, 0, 4),
            'nossoNumero'     => substr($campoLivre, 14, 10),
            'nossoNumeroDv'   => substr($campoLivre, 24, 1),
            'nossoNumeroFull' => substr($campoLivre, 14, 11),
            'contaCorrente'   => substr($campoLivre, 4, 10),
        ];
    }

    /**
     * AGÊNCIA / CÓDIGO DO BENEFICIÁRIO: deverá ser preenchido com o código da agência,
     * contendo 4 (quatro) caracteres / Conta Corrente com 10 (dez) caracteres. Ex.
     * 9999/999999999-9. Obs.: Preencher com zeros à direita quando necessário.
     * @return string
     */
    public function getAgenciaCodigoBeneficiario()
    {
        return Util::numberFormatGeral($this->getAgencia(), 4) . '-'. $this->getAgenciaDv() . ' / ' . Util::numberFormatGeral($this->getConta(), 7) . '-' . $this->getContaDv();
    }

    /**
     * Define o campo CIP do boleto
     *
     * @param int $cip
     * @return Sisprime
     */
    public function setCip($cip)
    {
        $this->cip = $cip;
        $this->variaveis_adicionais['cip'] = $this->getCip();

        return $this;
    }

    /**
     * Retorna o campo CIP do boleto
     *
     * @return string
     */
    public function getCip()
    {
        return Util::numberFormatGeral($this->cip, 3);
    }

    /**
     * Seta o código do cliente.
     *
     * @param mixed $codigoCliente
     *
     * @return Sisprime
     */
    public function setCodigoCliente($codigoCliente)
    {
        $this->codigoCliente = $codigoCliente;

        return $this;
    }

    /**
     * Retorna o codigo do cliente.
     *
     * @return string
     */
    public function getCodigoCliente()
    {
        return $this->codigoCliente;
    }

      /**
     * Retorna a linha digitável do boleto
     *
     * @return string
     * @throws ValidationException
     */
    public function getLinhaDigitavel()
    {
        if (! empty($this->campoLinhaDigitavel)) {
            return $this->campoLinhaDigitavel;
        }
        
        $this->campoLinhaDigitavel = Util::formatLinhaDigitavel($this->codigoBarras2LinhaDigitavel($this->getCodigoBarras()));
        return $this->campoLinhaDigitavel;
    }

    public function codigoBarras2LinhaDigitavel($codigo)
    {
        $parte1 = substr($codigo, 0, 4) . substr($codigo, 19, 5);
        $parte1 .= Util::modulo10($parte1);

        $parte2 = substr($codigo, 24, 10);
        $parte2 .= Util::modulo10($parte2);

        $parte3 = substr($codigo, 34, 10);
        $parte3 .= Util::modulo10($parte3);

        $parte4 = substr($codigo, 4, 1);

        $parte5 = substr($codigo, 5, 14);
        
        return $parte1 . $parte2 . $parte3 . $parte4 . $parte5;
    }
       /**
     * Retorna o código de barras
     *
     * @return string
     * @throws ValidationException
     */
    public function getCodigoBarras()
    {
        if (! empty($this->campoCodigoBarras)) {
            return $this->campoCodigoBarras;
        }

        if (! $this->isValid($messages)) {
            throw new ValidationException('Campos requeridos pelo banco, aparentam estar ausentes ' . $messages);
        }

        $codigo = Util::numberFormatGeral($this->getCodigoBanco(), 3)
            . $this->getMoeda()
            . Util::fatorVencimento($this->getDataVencimento())
            . Util::numberFormatGeral($this->getValor(), 10)
            . $this->getCampoLivre();
        
        $resto = Util::modulo11($codigo, 2, 9, 1);
        
        $resto = 11 - $resto;
        
        if($resto == 0 || $resto == 1 || $resto > 9){
            $dv = 1;    
        }else{
            $dv = $resto;
        }
        
        $this->campoCodigoBarras = substr($codigo, 0, 4) . $dv . substr($codigo, 4);
        return $this->campoCodigoBarras;
    }

    /**
     * Return Boleto Array.
     *
     * @return array
     */
    public function toAPI()
    {
        $data = [
            'beneficiarioVariacaoCarteira' => $this->getVariacaoCarteira(),
            'seuNumero'     => $this->getNumero(),
            'valor'         => Util::nFloat($this->getValor(), 2, false),
            'vencimento'    => $this->getDataVencimento()->format('Y-m-d'),
            'nossoNumero'   => null,
            'pagador' => [
                'nomeRazaoSocial' => substr($this->getPagador()->getNome(), 0, 40),
                'tipoPessoa'      => strlen(Util::onlyNumbers($this->getPagador()->getDocumento())) == 14 ? 'J' : 'F',
                'numeroDocumento' => Util::onlyNumbers($this->getPagador()->getDocumento()),
                'nomeFantasia'    => $this->getPagador()->getNomeFantasia(),
                'email'           => $this->getPagador()->getEmail(),
                'endereco' => [
                    'logradouro' => $this->getPagador()->getEndereco(),
                    'bairro'     => $this->getPagador()->getBairro(),
                    'cidade'     => $this->getPagador()->getCidade(),
                    'uf'         => $this->getPagador()->getUf(),
                    'cep'        => Util::onlyNumbers($this->getPagador()->getCep())
                ]
            ],
            'mensagensFichaCompensacao' => array_filter(array_map(function($instrucao) {
                return is_null($instrucao) ? null : trim($instrucao);
            }, $this->getInstrucoes()))
        ];

        if ($this->getDesconto()) {
            $data['desconto'] = [
                'indicador' => '0',
                'dataLimite' => $this->getDataDesconto()->format('Y-m-d'),
                'valor' => Util::nFloat($this->getDesconto()),
            ];
        }

        if ($this->getMulta()) {
            $data['multa'] = [
                'indicador' => '0',
                'dataLimite' => ($this->getDataVencimento()->copy())->addDay()->format('Y-m-d'),
                'valor' => Util::nFloat($this->getMulta()),
            ];
        }

        if ($this->getJuros()) {
            $data['juros'] = [
                'indicador' => '0',
                'dataLimite' => ($this->getDataVencimento()->copy())->addDays($this->getJurosApos() > 0 ? $this->getJurosApos() : 1)->format('Y-m-d'),
                'valor' => Util::nFloat($this->getJuros()),
            ];
        }

        return array_filter($data);
    }

    /**
     * @param object $boleto
     * @param array $appends
     *
     * @return BoletoContract
     * @throws \Exception
     */
    public static function fromAPI($boleto, $appends=[])
    {
        if(!array_key_exists('beneficiario', $appends)) {
            throw new \Exception('Informe o beneficiario');
        }

        if(!array_key_exists('conta', $appends)) {
            throw new \Exception('Informe a conta');
        }

        $ipte = Util::IPTE2Variveis($boleto->linhaDigitavel);

        $aSituacao = [
            'PAGO'      => AbstractBoleto::SITUACAO_PAGO,
            'LIQUIDADO' => AbstractBoleto::SITUACAO_PAGO,
            'BAIXADO'   => AbstractBoleto::SITUACAO_BAIXADO,
            'VENCIDO'   => AbstractBoleto::SITUACAO_ABERTO,
            'ABERTO'    => AbstractBoleto::SITUACAO_ABERTO,
            'EXPIRADO'  => AbstractBoleto::SITUACAO_BAIXADO,
        ];
        $dateUS = preg_match('/[0-9]{4}-[0-9]{2}-[0-9]{2}.*/', $boleto->dataDeVencimento);

        return new static(array_merge(array_filter([
            'nossoNumero'       => $boleto->nossoNumero,
            'dataSituacao'      => Carbon::now(),
            'valorRecebido'     => $boleto->valor,
            'situacao'          => Arr::get($aSituacao, $boleto->status, $boleto->status),
            'dataVencimento'    => Carbon::createFromFormat($dateUS ? 'Y-m-d' : 'd/m/Y', $boleto->dataDeVencimento),
            'valor'             => $boleto->valor,
            'carteira'          => isset($ipte['campo_livre_parsed']['carteira']) ? $ipte['campo_livre_parsed']['carteira'] : '9',
            'operacao'          => isset($ipte['campo_livre_parsed']['convenio']) ? $ipte['campo_livre_parsed']['convenio'] : null,
        ]), $appends));
    }

    /**
     * Mostra exception ao erroneamente tentar setar o nosso número
     *
     * @param string $nossoNumero
     * @return void
     */
    public function setNossoNumero($nossoNumero)
    {
        $nnClean = substr(Util::onlyNumbers($nossoNumero), -11);

        $this->campoNossoNumero = $nnClean;
    }
}
