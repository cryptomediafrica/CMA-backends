 // connect to Moralis server
 const serverUrl = "https://0erlqbhytxjl.usemoralis.com:2053/server";
 const appId = "KFNDGj1htqTiQygjIwWjHuSxt4QP6OXb2tQ19pT4";
 Moralis.start({ serverUrl, appId });
 
init = async () => {
  window.web3 = await Moralis.Web3.enable();
  const user = await Moralis.User.current();
  console.log("user");
  console.log(user);
}

login = async () => {
     await Moralis.Web3.authenticate()
 .then(async function (user) {
    let _username = document.getElementById('username',username).value;
    let _password = document.getElementById('password' ,password).value;
      if(_username != '' || _password != ''){
         if(_username != ''){user.set("name", _username);}
         if(_password != ''){user.set("name", _password);}
         await user.save();
      }
      console.log("logged in user:", user);
      console.log(user.get("ethAddress"));
      window.location.href = "dashboard.html";   
    })
}

async function listenToUpdates(){
    let query = new Moralis.Query("EthTransactions");
    let subscription = await query.subscribe();

    subscription.on("create",(object)=>{
      console.log("new transaction!!");
      console.log(object);
    });
}
    listenToUpdates();
document.getElementById("btn-login").onclick = login;

init();