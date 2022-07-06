// connect to Moralis server
const serverUrl = "https://0erlqbhytxjl.usemoralis.com:2053/server";
const appId = "KFNDGj1htqTiQygjIwWjHuSxt4QP6OXb2tQ19pT4";
Moralis.start({ serverUrl, appId });

// build table transactions

async function Transactions(){
  const chainToQuery = 'Eth'
  const transactions = await Moralis.Web3API.account.getTransactions({chain: chainToQuery}).then(buildTableTransactions);

}

function buildTableTransactions(_data){
  console.log(_data)
  const current = ethereum.selectedAddress;
  let data = _data.result;
  document.getElementById("resultTransactions").innerHTML = `<table class="table table-dark table-striped" id="transactionsTable">
                                                          </table>`;
  const table = document.getElementById("transactionsTable");
  const rowHeader = `<thead>
                          <tr>
                              <th>Type</th>
                              <th>From/To</th>
                              <th>Value</th>
                          </tr>
                      </thead>`
  table.innerHTML += rowHeader;
  for (let i=0; i < data.length; i++){
      let type = "";
      if (data[i].from_address == current){
          type = "Outgoing";
          fromTo = data[i].to_address;
      }
      else {
          type = "Incoming";
          fromTo = data[i].from_address;
      }
      let row = `<tr>
                      <td>${type}</td>
                      <td>${fromTo}</td>
                      <td>${data[i].value/10**18}</td>
                  </tr>`
      table.innerHTML += row
  }
}

// get Native Balances

  async function Nativebalances(){
  const chainToQuery = 'Eth'
  const balances = await Moralis.Web3API.account.getTokenBalances({chain: chainToQuery}).then(buildTableBalances);

}
function buildTableBalances(data){
  console.log(data)
  document.getElementById("resultnativebalances").innerHTML = `<table class="table table-dark table-striped" id="balancesTable">
                                                          </table>`;
  const table = document.getElementById("balancesTable");
  const rowHeader = `<thead>
                          <tr>
                              <th>Token</th>
                              <th>Symbol</th>
                              <th>Balance</th>
                          </tr>
                      </thead>`
  table.innerHTML += rowHeader;
  for (let i=0; i < data.length; i++){
      let row = `<tr>
                      <td>${data[i].name}</td>
                      <td>${data[i].symbol}</td>
                      <td>${data[i].balance/10**18}</td>
                  </tr>`
      table.innerHTML += row
  }
}

// get ERC20 token balances

balances = async () => {
  const chainToQuery = 'Eth'
  const options = ( {chain: chainToQuery})
  const balances = await Moralis.Web3.getAllERC20(options).then(buildTableBalance);
  
}
function buildTableBalance(data){
  console.log(data)
  document.getElementById("resultBalances").innerHTML = `<table class="table table-dark table-striped" id="balanceTable">
                                                          </table>`;
  const table = document.getElementById("balanceTable");
  const rowHeader = `<thead>
                          <tr>
                              <th>Token</th>
                              <th>Symbol</th>
                              <th>Balance</th>
                              <th>Decimals</th>
                              <th>Contract-Address</th>
                          </tr>
                      </thead>`
  table.innerHTML += rowHeader;
  for (let i=0; i < data.length; i++){
      let row = `<tr>
                      <td>${data[i].name}</td>
                      <td>${data[i].symbol}</td>
                      <td>${data[i].balance/10**18}</td>
                      <td>${data[i].decimals}</td>
                      <td>${data[i].contract}</td>
                  </tr>`
      table.innerHTML += row
  }
}

//build table NFTs

async function getNFTs(){
  const chainToQuery = 'Eth'
  const nft = await Moralis.Web3API.account.getNFTs({chain: chainToQuery}).then(buildTableNFT);
}

function buildTableNFT(_data){
  console.log(_data)
  let data = _data.result;
  console.log(data)
  document.getElementById("resultNFT").innerHTML = `<table class="table table-dark table-striped" id="nftTable">
                                                          </table>`;
  const table = document.getElementById("nftTable");
  const rowHeader = `<thead>
                          <tr>
                              <th>ID</th>
                              <th>Type</th>
                              <th>Contract</th>
                          </tr>
                      </thead>`
  table.innerHTML += rowHeader;
  for (let i=0; i < data.length; i++){
      let row = `<tr>
                      <td>${data[i].token_id}</td>
                      <td>${data[i].contract_type}</td>
                      <td>${data[i].token_address}</td>
                  </tr>`
      table.innerHTML += row
  }
}

// transfer ethereum
 transferETH = async () => {
        let _amount = String(document.querySelector('#amountOfETH').value);
        let _address = document.querySelector('#addressToReceive').value;

        const options = {type: "native", amount: Moralis.Units.ETH (_amount), receiver: _address}
                        await Moralis.Web3.authenticate()
        let result = await Moralis.transfer(options);
                        console.log(result);
                        alert(`transferring ${_amount} ETH to your requested address. Please allow some time to process your transaction.`);
}
document.querySelector("#ETHTransferButton").onclick = transferETH;

// transfer ERC20
transferERC20 = async () => {
        let _amount = String(document.querySelector('#ERC20TransferAmount').value);
        let _decimals = String(document.querySelector('#ERC20TransferDecimals').value);
        let _address = String(document.querySelector('#ERC20TransferAddress').value);
        let _contract = String(document.querySelector('#ERC20TransferContract').value);

        const options = {type: "erc20", 
                        amount: Moralis.Units.Token(_amount, _decimals), 
                        receiver: _address,
                        contract_address: _contract}
                        await Moralis.Web3.authenticate()
        let result = await Moralis.transfer(options)    
                        console.log(result);
                        alert(`transferring ${_amount} ERC20 to your requested address. Please allow some time to process your transaction.`);
}
document.querySelector('#ERC20TransferButton').onclick = transferERC20;


// transfer NFTs
transferNFTs = async () => {
  let _type = String(document.querySelector('#nft-transfer-type').value);
  let _address = String(document.querySelector('#nft-transfer-receiver').value);
  let _amount = String(document.querySelector('#nft-transfer-amount').value);
  let _contract = String(document.querySelector('#nft-transfer-contract-address').value);
  let _token = String(document.querySelector('#nft-transfer-token-id').value);

  const options = {type: _type,receiver: _address,amount: _amount,contract_address: _contract,token_id: _token}
                  await Moralis.Web3.authenticate()
  let result = await Moralis.transfer(options)    
                  console.log(result);
                  alert(`transferring ${_amount} NFT to your requested address. Please allow some time to process your transaction.`);
}
document.querySelector('#btn-transfer-selected-nft').onclick = transferNFTs;


  async function getNFT() {
   let address= document.getElementById("nftaddress").value;
   let chain= document.getElementById("nftchain").value;

   const options = { chain: chain,address: address};
   const nfts = await Moralis.Web3.getNFTs(options);
   console.log(nfts);

   nfts.forEach( e => {
     let url = e.token_uri;

     
     fetch(url)
     .then(response => response.json())
     .then(data => {

      fixURL = (url) => {
        if (url.startWith("ipfs")) {
          return "https://ipfs.moralis.io:2053/ipfs/" + url.split("ipfs://").slice(-1)
        }
        else{
          return url + "?format=json"
        }
      }
          let currentDiv = document.getElementById("content");
          
          let content = `
          <div class="nft">
          <b>${data.name}</b>   
          <img width=100 height=100 src="${data.image}"/>
          <b>${data.token_address}</b> 
          </div>
          `
          currentDiv.innerHTML += content;
     })
   })
 }