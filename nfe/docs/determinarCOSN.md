flowchart TD

A[Inicio] --> B{CRT igual a 1}

B -- Nao --> E1[Erro: somente simples nacional]
B -- Sim --> C{Regime ICMS valido}

C -- Nao --> C1[Forca regime para 1]
C -- Sim --> D[Checar credito ICMS]

D --> E{Regime ICMS}

%% --------- CASE 1 ----------
E -- 1 --> R1{Tem credito}

R1 -- Sim --> CS101[CSOSN 101]
R1 -- Nao --> CS102A[CSOSN 102]

%% --------- CASE 2 ----------
E -- 2 --> R2{Destinatario nao contribuinte}

R2 -- Sim --> CS102B[CSOSN 102]
R2 -- Nao --> R2B{Dados ST existem}

R2B -- Nao --> CS102C[CSOSN 102]
R2B -- Sim --> R2C{Tem credito}

R2C -- Sim --> CS201[CSOSN 201]
R2C -- Nao --> CS202[CSOSN 202]

%% --------- CASE 3 ----------
E -- 3 --> CS500[CSOSN 500]

%% --------- CASE 4 ----------
E -- 4 --> R4{Produto tem ST}

R4 -- Nao --> CS103A[CSOSN 103]
R4 -- Sim --> R4A{Dest contrib}

R4A -- Nao --> CS103B[CSOSN 103]
R4A -- Sim --> CS203[CSOSN 203]

%% --------- CASE 5 ----------
E -- 5 --> CS400[CSOSN 400]

%% --------- CASE 6 ----------
E -- 6 --> R6{Isencao formal}

R6 -- Sim --> CS103C[CSOSN 103]
R6 -- Nao --> CS900[CSOSN 900]

%% --------- CASE 7 ----------
E -- 7 --> CS300[CSOSN 300]

%% --------- DEFAULT ----------
E -- outro --> CS102F[CSOSN 102 fallback]
